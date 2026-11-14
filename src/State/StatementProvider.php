<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Statement;
use App\ApiResource\StatementDetail;
use App\ApiResource\StatementLine;
use App\Entity\Budget;
use App\Entity\User;
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
use Symfony\Component\Validator\ConstraintViolation;

/**
 * Builds the statement of the current user over a period of whole months, compared to their budget.
 *
 * @implements ProviderInterface<Statement>
 */
final readonly class StatementProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private BudgetRepository $budgetRepository,
        private CategoryRepository $categoryRepository,
        private TransactionRepository $transactionRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Statement
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('User not found');
        }

        $period = Period::fromString((string) ($uriVariables['period'] ?? ''));
        [$from, $to, $start, $end, $months] = [$period->from, $period->to, $period->start, $period->end, $period->months];

        $budget = $this->findBudget($user);
        $currency = $budget?->getCurrency() ?? Currency::EUR;

        // Signed sums per root category; expenses are negative, shown as positive spending
        $byRoot = [CategoryType::EXPENSE->value => [], CategoryType::INCOME->value => []];
        $counts = []; // root category id => number of transactions
        foreach ($this->transactionRepository->sumSplitsByRootCategory($user, $currency, $start, $end) as $row) {
            if (isset($byRoot[$row['type']->value])) {
                $id = $row['root']->toRfc4122();
                $byRoot[$row['type']->value][$id] = Money::toCents($row['amount']);
                $counts[$id] = $row['count'];
            }
        }
        $expenses = array_map(static fn (int $cents) => -$cents, $byRoot[CategoryType::EXPENSE->value]);
        $incomes = $byRoot[CategoryType::INCOME->value];

        $statement = new Statement();
        $statement->period = $from === $to ? $from : $from . '..' . $to;
        $statement->from = $from;
        $statement->to = $to;
        $statement->months = $months;
        $statement->currency = $currency;
        $statement->budget = $budget;
        $statement->targetAmount = Money::fromCents($budget ? Money::toCents($budget->getAmount()) * $months : 0);

        // 1. Budget lines, in budget order
        $budgetedCents = 0;
        foreach ($budget?->getLines() ?? [] as $line) {
            $id = $line->getCategory()->getId()->toRfc4122();
            $cents = $expenses[$id] ?? 0;
            unset($expenses[$id]);
            $budgetedCents += $cents;

            $statement->lines[] = new StatementLine(
                $line->getCategory(),
                Money::fromCents($cents),
                Money::fromCents(Money::toCents($line->getAmount()) * $months),
                $counts[$id] ?? 0,
            );
        }

        // 2. Every other expense root (target 0), including deleted ones with spending, by decreasing amount
        $unbudgetedCents = array_sum($expenses);
        $budgeted = array_map(static fn (StatementLine $line) => $line->category->getId()->toRfc4122(), $statement->lines);
        foreach ($this->categoryRepository->findActiveRoots(CategoryType::EXPENSE, $user) as $root) {
            $id = $root->getId()->toRfc4122();
            if (!in_array($id, $budgeted, true)) {
                $expenses[$id] ??= 0;
            }
        }
        foreach ($this->buildLines($expenses, $counts) as $line) {
            $line->targetAmount = '0.00';
            $statement->lines[] = $line;
        }
        $statement->unbudgetedAmount = Money::fromCents($unbudgetedCents);
        $statement->details = $this->buildDetails($user, $currency, $start, $end);

        $totals = $this->transactionRepository->sumBySign($user, $currency, $start, $end);
        $uncategorizedCents = -(Money::toCents($totals['debits']) - Money::toCents($totals['categorizedDebits']));
        $uncategorizedIncomeCents = Money::toCents($totals['credits']) - Money::toCents($totals['categorizedCredits']);

        $uncategorizedCounts = $this->transactionRepository->countUncategorizedBySign($user, $currency, $start, $end);

        $statement->uncategorizedAmount = Money::fromCents($uncategorizedCents);
        $statement->uncategorizedCount = $uncategorizedCounts['debits'];
        $statement->amount = Money::fromCents($budgetedCents + $unbudgetedCents + $uncategorizedCents);

        $statement->income = $this->buildLines($incomes, $counts);
        $statement->uncategorizedIncomeAmount = Money::fromCents($uncategorizedIncomeCents);
        $statement->uncategorizedIncomeCount = $uncategorizedCounts['credits'];
        $statement->incomeAmount = Money::fromCents(array_sum($incomes) + $uncategorizedIncomeCents);

        return $statement;
    }

    /**
     * Amounts per category, sorted by parent then decreasing amount.
     *
     * @return list<StatementDetail>
     */
    private function buildDetails(User $user, Currency $currency, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $details = [];
        foreach ($this->transactionRepository->sumSplitsByCategory($user, $currency, $start, $end) as $row) {
            // Transfers are not part of the statement
            if (!in_array($row['type'], [CategoryType::EXPENSE, CategoryType::INCOME], true)) {
                continue;
            }

            $category = $this->categoryRepository->find($row['category']);
            if (!$category) {
                continue;
            }

            $cents = Money::toCents($row['amount']);
            $details[] = new StatementDetail(
                $category,
                $category->getParent() ?? $category,
                Money::fromCents(CategoryType::EXPENSE === $row['type'] ? -$cents : $cents),
                $row['count'],
            );
        }

        usort($details, static fn (StatementDetail $a, StatementDetail $b) =>
            [$a->parent->getId()->toRfc4122(), Money::toCents($b->amount)]
            <=> [$b->parent->getId()->toRfc4122(), Money::toCents($a->amount)]);

        return $details;
    }

    /**
     * The user's budget, ignored without the budget read access.
     */
    private function findBudget(User $user): ?Budget
    {
        $budget = $this->budgetRepository->findOneBy(['owner' => $user]);

        return $budget && $this->security->isGranted(BudgetVoter::READ, $budget) ? $budget : null;
    }

    /**
     * @param array<string, int> $centsByRoot root category id => cents
     * @param array<string, int> $counts root category id => number of transactions
     * @return list<StatementLine> sorted by decreasing amount
     */
    private function buildLines(array $centsByRoot, array $counts): array
    {
        arsort($centsByRoot);

        // A handful of roots, mostly already in the identity map
        $lines = [];
        foreach ($centsByRoot as $id => $cents) {
            $category = $this->categoryRepository->find(Uuid::fromString($id));
            if ($category) {
                $lines[] = new StatementLine($category, Money::fromCents($cents), transactionCount: $counts[$id] ?? 0);
            }
        }

        return $lines;
    }
}
