<?php

namespace App\Doctrine\Extension;


use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Account;
use App\Entity\AccountConnection;
use App\Entity\Category;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class CurrentUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Resources filtered on their owner.
     * true = rows without owner (shared, e.g. system categories) are also visible to every user.
     */
    private const array SUPPORTED_ENTITIES = [
        AccountConnection::class => false,
        Account::class => false,
        Category::class => true,
    ];

    public function __construct(
        private Security $security,
    )
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->addWhere($queryBuilder, $resourceClass);

        // Soft-deleted categories stay reachable by IRI (linked transactions) but are not listed
        if ($resourceClass === Category::class) {
            $queryBuilder->andWhere(sprintf('%s.deletedAt IS NULL', $queryBuilder->getRootAliases()[0]));
        }
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    private function addWhere(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        $user = $this->security->getUser();
        if (!$user || !array_key_exists($resourceClass, self::SUPPORTED_ENTITIES)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $condition = sprintf('IDENTITY(%s.owner) = :owner', $rootAlias);

        if (self::SUPPORTED_ENTITIES[$resourceClass]) {
            $condition = sprintf('(%s OR %s.owner IS NULL)', $condition, $rootAlias);
        }

        $queryBuilder
            ->andWhere($condition)
            ->setParameter('owner', $user->getId(), UuidType::NAME);
    }
}
