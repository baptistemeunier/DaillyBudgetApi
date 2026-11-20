<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UserAction;
use App\Entity\User;
use App\Enum\Role;
use App\Enum\UserActionType;
use App\Repository\AccountConnectionRepository;
use App\Repository\TransactionRepository;
use App\Service\ConnectionStatusResolver;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<UserAction>
 */
readonly class UserActionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private AccountConnectionRepository $accountConnectionRepository,
        private TransactionRepository $transactionRepository,
        private ConnectionStatusResolver $connectionStatusResolver,
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
            // Only the owner of a connection can reconnect or configure it
            $connections = $this->accountConnectionRepository->findBy(['owner' => $user, 'deletedAt' => null]);
            foreach ($connections as $connection) {
                $type = $this->connectionStatusResolver->resolve($connection);
                if ($type !== null) {
                    $actions[] = UserAction::forConnection($type, $connection);
                }
            }
        }

        if ($this->security->isGranted(Role::ACCOUNT_READ->value)) {
            $count = $this->transactionRepository->countUncategorized();
            if ($count > 0) {
                $action = new UserAction(UserActionType::TRANSACTIONS_TO_CATEGORIZE, UserActionType::TRANSACTIONS_TO_CATEGORIZE->value);
                $action->count = $count;
                $actions[] = $action;
            }

            $count = $this->transactionRepository->countAutoCategorized();
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
}
