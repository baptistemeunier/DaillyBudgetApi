<?php

namespace App\Service;

use App\Entity\CategorizationRule;
use App\Entity\Transaction;
use App\Entity\TransactionSplit;
use App\Enum\SplitSource;
use App\Message\AccountCategorizationRequest;
use App\Repository\AccountRepository;
use App\Util\Money;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Applies the categorization rules: a transaction of a synced account, without any split set or confirmed by the user,
 * gets a single split of its whole amount on the category of the best matching rule (source "rule").
 * Best rule: the longest pattern, then the one with a direction. The changes are flushed by the caller.
 * The categorization runs asynchronously, per account (AccountCategorizationRequest).
 */
final readonly class TransactionCategorizer
{
    public function __construct(
        private AccountRepository $accountRepository,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @param CategorizationRule[] $rules all the rules (shared between the users)
     *
     * @return bool whether the splits of the transaction changed
     */
    public function categorize(Transaction $transaction, array $rules): bool
    {
        if ($transaction->getAccount()?->isManual() || $transaction->hasUserSplit()) {
            return false;
        }

        $rule = $this->findRule($transaction, $rules);
        if (null === $rule) {
            return false;
        }

        $splits = $transaction->getSplits();
        $current = $splits->first();
        if (1 === $splits->count() && $current->getRule() === $rule && $current->getCategory() === $rule->getCategory()
            && Money::toCents($current->getAmount()) === Money::toCents($transaction->getAmount())) {
            return false;
        }

        foreach ($splits->toArray() as $split) {
            $transaction->removeSplit($split);
        }

        $transaction->addSplit(new TransactionSplit()
            ->setCategory($rule->getCategory())
            ->setAmount($transaction->getAmount())
            ->setSource(SplitSource::RULE)
            ->setRule($rule));

        return true;
    }

    /**
     * @param CategorizationRule[] $rules
     */
    public function findRule(Transaction $transaction, array $rules): ?CategorizationRule
    {
        $amount = $transaction->getAmount();
        if (null === $amount || !Money::isDecimal($amount)) {
            return null;
        }

        $cents = Money::toCents($amount);
        $best = null;

        foreach ($rules as $rule) {
            if (!$rule->matches($transaction->getMerchantKey(), $cents)) {
                continue;
            }

            if (null === $best || $this->isBetter($rule, $best)) {
                $best = $rule;
            }
        }

        return $best;
    }

    /**
     * Asks for the categorization of all the active synced accounts (async, see AccountCategorizationRequestHandler),
     * e.g. after a change of the rules. Call it once the rules are flushed.
     */
    public function requestCategorization(): void
    {
        foreach ($this->accountRepository->findSynced() as $account) {
            $this->bus->dispatch(new AccountCategorizationRequest($account->getId()));
        }
    }

    private function isBetter(CategorizationRule $rule, CategorizationRule $than): bool
    {
        return (strlen($rule->getPattern()) <=> strlen($than->getPattern())
            ?: (null !== $rule->getDirection()) <=> (null !== $than->getDirection())) > 0;
    }
}
