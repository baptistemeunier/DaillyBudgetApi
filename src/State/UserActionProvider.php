<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UserAction;
use App\Entity\AccountConnection;
use App\Entity\User;
use App\Enum\RequisitionStatus;
use App\Enum\Role;
use App\Enum\UserActionType;
use App\Repository\AccountConnectionRepository;
use App\Repository\TransactionRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<UserAction>
 */
readonly class UserActionProvider implements ProviderInterface
{
    // A connection expiring within this delay is reported
    private const int CONNECTION_EXPIRING_DAYS = 7;

    public function __construct(
        private Security $security,
        private AccountConnectionRepository $accountConnectionRepository,
        private TransactionRepository $transactionRepository,
    ) {
    }

    /**
     * @return UserAction[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('User not found');
        }

        $actions = [];

        if ($this->security->isGranted(Role::ACCOUNT_CONNECTION_READ->value)) {
            $connections = $this->accountConnectionRepository->findBy(['owner' => $user, 'deletedAt' => null]);
            foreach ($connections as $connection) {
                $type = $this->getConnectionActionType($connection);
                if ($type !== null) {
                    $actions[] = UserAction::forConnection($type, $connection);
                }
            }
        }

        if ($this->security->isGranted(Role::ACCOUNT_READ->value)) {
            $count = $this->transactionRepository->countUncategorized($user);
            if ($count > 0) {
                $action = new UserAction(UserActionType::TRANSACTIONS_TO_CATEGORIZE, UserActionType::TRANSACTIONS_TO_CATEGORIZE->value);
                $action->count = $count;
                $actions[] = $action;
            }

            $count = $this->transactionRepository->countAutoCategorized($user);
            if ($count > 0) {
                $action = new UserAction(UserActionType::TRANSACTIONS_TO_REVIEW, UserActionType::TRANSACTIONS_TO_REVIEW->value);
                $action->count = $count;
                $actions[] = $action;
            }
        }

        // Highest priority first, then the soonest expiry
        usort($actions, fn (UserAction $a, UserAction $b) => $b->priority <=> $a->priority
            ?: ($a->expiresAt?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->expiresAt?->getTimestamp() ?? PHP_INT_MAX));

        return $actions;
    }

    private function getConnectionActionType(AccountConnection $connection): ?UserActionType
    {
        $status = $connection->getExternalStatus();
        $expiresAt = $connection->getAccessExpiresAt();
        $now = new \DateTimeImmutable();

        return match (true) {
            $status === null => null,
            // GoCardless keeps the requisition "LN" after the expiry: the date is enough
            $status === RequisitionStatus::EXPIRED,
            $status === RequisitionStatus::SUSPENDED,
            $status === RequisitionStatus::LINKED && $expiresAt !== null && $expiresAt <= $now => UserActionType::CONNECTION_EXPIRED,
            $status->needUserAction() => UserActionType::CONNECTION_TO_CONFIGURE,
            $status === RequisitionStatus::LINKED && $expiresAt !== null
                && $expiresAt <= $now->modify(sprintf('+%d days', self::CONNECTION_EXPIRING_DAYS)) => UserActionType::CONNECTION_EXPIRING,
            default => null,
        };
    }
}
