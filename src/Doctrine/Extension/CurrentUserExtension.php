<?php

namespace App\Doctrine\Extension;


use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Account;
use App\Entity\AccountConnection;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Transaction;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
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
        Budget::class => false,
        Category::class => true,
        Transaction::class => false,
    ];

    /**
     * Resources without owner: filtered on the owner of the related entity (target class).
     * The related entity must not be soft-deleted.
     */
    private const array SUPPORTED_ENTITIES_THROUGH_RELATION = [
        Transaction::class => Account::class,
    ];

    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
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
        $user = $this->security->getUser();
        if (!$user || !array_key_exists($resourceClass, self::SUPPORTED_ENTITIES)) {
            return;
        }

        // Shared: rows without owner are visible to every user
        $shared = self::SUPPORTED_ENTITIES[$resourceClass];
        $ownerAlias = $queryBuilder->getRootAliases()[0];

        if (array_key_exists($resourceClass, self::SUPPORTED_ENTITIES_THROUGH_RELATION)) {
            $ownerAlias = $this->joinOwnerRelation($queryBuilder, $queryNameGenerator, $resourceClass, $shared);
        }

        $parameter = $queryNameGenerator->generateParameterName('owner');
        $condition = sprintf('IDENTITY(%s.owner) = :%s', $ownerAlias, $parameter);

        if ($shared) {
            $condition = sprintf('(%s OR %s.owner IS NULL)', $condition, $ownerAlias);
        }

        $queryBuilder
            ->andWhere($condition)
            ->setParameter($parameter, $user->getId(), UuidType::NAME);
    }

    /**
     * Joins the related entity holding the owner and returns its alias.
     */
    private function joinOwnerRelation(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, bool $shared): string
    {
        $targetClass = self::SUPPORTED_ENTITIES_THROUGH_RELATION[$resourceClass];
        $associations = array_filter(
            $this->entityManager->getClassMetadata($resourceClass)->getAssociationMappings(),
            fn (AssociationMapping $mapping) => $mapping->targetEntity === $targetClass && $mapping->isToOne(),
        );

        if (count($associations) !== 1) {
            throw new LogicException(sprintf('%s must have exactly one relation to %s, %d found.', $resourceClass, $targetClass, count($associations)));
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $alias = $queryNameGenerator->generateJoinAlias('owner');
        $join = sprintf('%s.%s', $rootAlias, array_key_first($associations));

        // Shared: keep the rows without relation (hence without owner)
        $shared ? $queryBuilder->leftJoin($join, $alias) : $queryBuilder->join($join, $alias);

        // Rows of a soft-deleted related entity are hidden
        if ($this->entityManager->getClassMetadata($targetClass)->hasField('deletedAt')) {
            $queryBuilder->andWhere($shared
                ? sprintf('(%1$s.deletedAt IS NULL OR %1$s.id IS NULL)', $alias)
                : sprintf('%s.deletedAt IS NULL', $alias));
        }

        return $alias;
    }
}
