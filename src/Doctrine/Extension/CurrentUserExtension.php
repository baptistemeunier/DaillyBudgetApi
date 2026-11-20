<?php

namespace App\Doctrine\Extension;


use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Category;
use App\Entity\Notification;
use App\Entity\NotificationSettings;
use App\Entity\PushSubscription;
use App\Entity\Transaction;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class CurrentUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Personal resources, filtered on their owner.
     * The financial data (connections, accounts, transactions, budget, categories, rules) is shared between all the users.
     */
    private const array SUPPORTED_ENTITIES = [
        Notification::class,
        NotificationSettings::class,
        PushSubscription::class,
    ];

    public function __construct(
        private Security $security,
    )
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->addWhere($queryBuilder, $queryNameGenerator, $resourceClass);

        // Soft-deleted categories stay reachable by IRI (linked transactions) but are not listed
        if ($resourceClass === Category::class) {
            $queryBuilder->andWhere(sprintf('%s.deletedAt IS NULL', $queryBuilder->getRootAliases()[0]));
        }
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->addWhere($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    private function addWhere(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass): void
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];

        // Transactions of a soft-deleted account are hidden
        if ($resourceClass === Transaction::class) {
            $accountAlias = $queryNameGenerator->generateJoinAlias('account');
            $queryBuilder
                ->join(sprintf('%s.account', $rootAlias), $accountAlias)
                ->andWhere(sprintf('%s.deletedAt IS NULL', $accountAlias));

            return;
        }

        $user = $this->security->getUser();
        if (!$user || !in_array($resourceClass, self::SUPPORTED_ENTITIES, true)) {
            return;
        }

        $parameter = $queryNameGenerator->generateParameterName('owner');
        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.owner) = :%s', $rootAlias, $parameter))
            ->setParameter($parameter, $user->getId(), UuidType::NAME);
    }
}
