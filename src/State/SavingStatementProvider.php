<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SavingEnvelope;
use App\ApiResource\SavingMonth;
use App\ApiResource\SavingStatement;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\CategoryType;
use App\Enum\Currency;
use App\Repository\BudgetRepository;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Security\Voter\BudgetVoter;
use App\Util\Money;
use App\Util\Period;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Builds the savings dashboard of the current user over a period of whole months,
 * from the transactions of their savings accounts.
 *
 * @implements ProviderInterface<SavingStatement>
 */
final readonly class SavingStatementProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private BudgetRepository $budgetRepository,
        private CategoryRepository $categoryRepository,
        private TransactionRepository $transactionRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SavingStatement
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('User not found');
        }

        $period = Period::fromString((string) ($uriVariables['period'] ?? ''));
        $currency = $this->getCurrency($user);

        $statement = new SavingStatement();
        $statement->period = $period->period;
        $statement->from = $period->from;
        $statement->to = $period->to;
        $statement->months = $period->months;
        $statement->currency = $currency;

        // Credits / debits per month, and the part not assigned to a category
        $credits = $debits = array_fill_keys($period->monthKeys(), 0);
        $uncategorizedCents = 0;
        $uncategorizedCount = 0;
        foreach ($this->transactionRepository->findSavingsMovements($user, $currency, $period->start, $period->end) as $movement) {
            $month = $movement['bookingAt']->format('Y-m');
            $cents = Money::toCents($movement['amount']);
            if ($cents > 0) {
                $credits[$month] += $cents;
            } else {
                $debits[$month] -= $cents;
            }

            $rest = $cents - Money::toCents($movement['categorized']);
            if (0 !== $rest) {
                $uncategorizedCents += $rest;
                ++$uncategorizedCount;
            }
        }

        foreach (array_keys($credits) as $month) {
            $statement->monthly[] = new SavingMonth($month, Money::fromCents($credits[$month]), Money::fromCents($debits[$month]));
        }
        $statement->creditAmount = Money::fromCents(array_sum($credits));
        $statement->debitAmount = Money::fromCents(array_sum($debits));
        $statement->uncategorizedAmount = Money::fromCents($uncategorizedCents);
        $statement->uncategorizedCount = $uncategorizedCount;
        $statement->envelopes = $this->buildEnvelopes($user, $currency, $period);

        return $statement;
    }

    /**
     * Every active saving category, plus the deleted ones still holding money; by decreasing amount.
     *
     * @return list<SavingEnvelope>
     */
    private function buildEnvelopes(User $user, Currency $currency, Period $period): array
    {
        $total = $this->sumByCategory($user, $currency, null, $period);
        $periodic = $this->sumByCategory($user, $currency, $period->start, $period);

        $categories = [];
        foreach ($this->categoryRepository->findActiveSavingCategories($user) as $category) {
            $categories[$category->getId()->toRfc4122()] = $category;
        }
        foreach (array_keys(array_filter($total)) as $id) {
            $categories[$id] ??= $this->categoryRepository->find(Uuid::fromString($id));
        }

        $envelopes = [];
        foreach (array_filter($categories) as $id => $category) {
            $envelopes[] = new SavingEnvelope(
                $category,
                Money::fromCents($total[$id] ?? 0),
                Money::fromCents($periodic[$id] ?? 0),
                $category->getTargetAmount(),
                $category->getTargetDate(),
            );
        }

        usort($envelopes, static fn (SavingEnvelope $a, SavingEnvelope $b) => Money::toCents($b->amount) <=> Money::toCents($a->amount));

        return $envelopes;
    }

    /**
     * Net cents per saving category on the savings accounts, from $start (null: the beginning) to the end of the period.
     *
     * @return array<string, int> category id => cents
     */
    private function sumByCategory(User $user, Currency $currency, ?\DateTimeImmutable $start, Period $period): array
    {
        $sums = [];
        foreach ($this->transactionRepository->sumSplitsByCategory($user, $currency, $start, $period->end, AccountType::SAVINGS) as $row) {
            if (CategoryType::SAVING === $row['type']) {
                $sums[$row['category']->toRfc4122()] = Money::toCents($row['amount']);
            }
        }

        return $sums;
    }

    /**
     * Budget currency, EUR without budget (same as the statement).
     */
    private function getCurrency(User $user): Currency
    {
        $budget = $this->budgetRepository->findOneBy(['owner' => $user]);

        return $budget && $this->security->isGranted(BudgetVoter::READ, $budget) ? $budget->getCurrency() : Currency::EUR;
    }
}
