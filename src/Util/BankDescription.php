<?php

namespace App\Util;

use App\Entity\Transaction;

/**
 * Description and detail of a transaction from its GoCardless data: the counterparty name
 * (debtor for a credit, creditor for a debit), else the start of the remittance information.
 * Used by the sync, and to rebuild the descriptions from the stored raw data.
 */
final class BankDescription
{
    public static function apply(Transaction $transaction, array $externalTransaction): void
    {
        $description = (Money::toCents($transaction->getAmount()) > 0)
            ? $externalTransaction['debtorName'] ?? null
            : $externalTransaction['creditorName'] ?? null;

        $detail = null;
        if(!empty($externalTransaction['remittanceInformationUnstructuredArray'])) {
            $detail = implode(' ', array_filter($externalTransaction['remittanceInformationUnstructuredArray']));
        }

        if(!$detail && !empty($externalTransaction['remittanceInformationUnstructured'])) {
            $detail = $externalTransaction['remittanceInformationUnstructured'];
        }

        if(!$description) {
            if(empty($detail)) {
                throw new \LogicException('Unable to determine transaction description.');
            }

            $description = mb_substr($detail, 0, 50);
            $detail = mb_strlen($detail) > 50 ? $detail : null;
        }

        $transaction
            ->setDescription(mb_substr($description, 0, 255))
            ->setDetail($detail);
    }
}
