<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\TransactionSplit;
use App\Enum\CategoryType;
use App\Util\Money;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Saving splits towards a manual account (savings / investment): keeps a mirror transaction there
 * (opposite amount, or counterpartAmount when the account is in another currency), linked by counterpartTransaction.
 * No matching with synced accounts: a saving split without account simply has no mirror.
 *
 * API Platform replaces the splits on each PATCH: the previous ones are read from the collection snapshot,
 * their mirror is reused by the new split with the same category, and deleted otherwise.
 */
final readonly class SavingMirrorLinker
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return Account[] manual accounts whose transactions changed (balance to recompute after the flush)
     */
    public function link(Transaction $transaction): array
    {
        $splits = $transaction->getSplits();
        $previous = $splits instanceof PersistentCollection ? $splits->getSnapshot() : [];
        $current = array_values($splits->toArray());

        // Previous splits no longer there: their mirrors can be reused by the new splits
        $pool = array_values(array_filter($previous, fn (TransactionSplit $split) => !in_array($split, $current, true)));
        $touched = [];

        foreach ($current as $split) {
            if (in_array($split, $previous, true) || $split->getCategory()?->getType() !== CategoryType::SAVING) {
                continue;
            }

            $account = $split->getCounterpartAccount();
            // No account given: the client does not send it back, keep the previous mirror of this category
            $old = $this->takeFromPool($pool, $split->getCategory(), $account);
            $account ??= $old?->getCounterpartTransaction()->getAccount();

            if (!$account) {
                continue;
            }

            $split->setCounterpartTransaction($this->saveMirror($transaction, $split, $account, $old));
            $touched[spl_object_id($account)] = $account;
        }

        // Mirrors not reused are deleted
        foreach ($pool as $old) {
            $mirror = $old->getCounterpartTransaction();
            if (!$mirror || !$mirror->getAccount()->isManual() || $this->isLinked($current, $mirror)) {
                continue;
            }

            $this->entityManager->remove($mirror);
            $touched[spl_object_id($mirror->getAccount())] = $mirror->getAccount();
        }

        return array_values($touched);
    }

    /**
     * Removes from the pool and returns the first previous split with this category having a mirror
     * (on $account when given).
     *
     * @param TransactionSplit[] $pool
     */
    private function takeFromPool(array &$pool, ?Category $category, ?Account $account): ?TransactionSplit
    {
        foreach ($pool as $key => $old) {
            $mirror = $old->getCounterpartTransaction();
            if ($mirror && $mirror->getAccount()->isManual() && $old->getCategory() === $category
                && (null === $account || $mirror->getAccount() === $account)) {
                unset($pool[$key]);

                return $old;
            }
        }

        return null;
    }

    /**
     * @param TransactionSplit[] $splits
     */
    private function isLinked(array $splits, Transaction $mirror): bool
    {
        foreach ($splits as $split) {
            if ($split->getCounterpartTransaction() === $mirror) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirror in the account's currency: opposite of the split (same currency), or counterpartAmount
     * (other currency; without it, the previous mirror is kept if the split amount is unchanged).
     * Same dates and description as the origin, one split with the same saving category.
     */
    private function saveMirror(Transaction $origin, TransactionSplit $split, Account $account, ?TransactionSplit $old): Transaction
    {
        $mirror = $old?->getCounterpartTransaction();
        $currency = $account->getBalanceCurrency() ?? $origin->getCurrency();

        $amount = match (true) {
            $currency === $origin->getCurrency() => Money::fromCents(-Money::toCents($split->getAmount())),
            null !== $split->getCounterpartAmount() => Money::fromCents(Money::toCents($split->getCounterpartAmount())),
            null !== $mirror && Money::toCents($old->getAmount()) === Money::toCents($split->getAmount()) => $mirror->getAmount(),
            default => throw new UnprocessableEntityHttpException(sprintf(
                'splits: the account "%s" is in %s, give the amount received (counterpartAmount).',
                $account->getDisplayName() ?? $account->getName(),
                $currency->value,
            )),
        };

        if (!$mirror) {
            $mirror = new Transaction()
                ->setAccount($account)
                ->setIsBooked(true);
            $this->entityManager->persist($mirror);
        }

        $mirror
            ->setCurrency($currency)
            ->setDescription($origin->getDescription())
            ->setReference($origin->getReference())
            ->setAmount($amount)
            ->setBookingAt($origin->getBookingAt() ?? $origin->getValueAt())
            ->setValueAt($origin->getValueAt() ?? $origin->getBookingAt());

        foreach ($mirror->getSplits() as $mirrorSplit) {
            $mirror->removeSplit($mirrorSplit);
        }
        $mirror->addSplit(new TransactionSplit()
            ->setCategory($split->getCategory())
            ->setAmount($amount)
            ->setCounterpartTransaction($origin));

        return $mirror;
    }
}
