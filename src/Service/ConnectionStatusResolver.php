<?php

namespace App\Service;

use App\Entity\AccountConnection;
use App\Enum\RequisitionStatus;
use App\Enum\UserActionType;

/**
 * What the user has to do about a bank connection, if anything (GET /user_actions and the daily notifications).
 */
final readonly class ConnectionStatusResolver
{
    // A connection expiring within this delay is reported
    public const int EXPIRING_DAYS = 7;

    public function resolve(AccountConnection $connection, \DateTimeImmutable $now = new \DateTimeImmutable()): ?UserActionType
    {
        $status = $connection->getExternalStatus();
        $expiresAt = $connection->getAccessExpiresAt();

        return match (true) {
            $status === null => null,
            // GoCardless keeps the requisition "LN" after the expiry: the date is enough
            $status === RequisitionStatus::EXPIRED,
            $status === RequisitionStatus::SUSPENDED,
            $status === RequisitionStatus::LINKED && $expiresAt !== null && $expiresAt <= $now => UserActionType::CONNECTION_EXPIRED,
            $status->needUserAction() => UserActionType::CONNECTION_TO_CONFIGURE,
            $status === RequisitionStatus::LINKED && $expiresAt !== null
                && $expiresAt <= $now->modify(sprintf('+%d days', self::EXPIRING_DAYS)) => UserActionType::CONNECTION_EXPIRING,
            default => null,
        };
    }
}
