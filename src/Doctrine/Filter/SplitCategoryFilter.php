<?php

namespace App\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Operation;
use App\Entity\TransactionSplit;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * ?category=<uuid or IRI> keeps the transactions with a split on exactly this category
 * (a root does not include its sub-categories: same rule as Statement details).
 */
final class SplitCategoryFilter implements FilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $value = $context['parameter']?->getValue();
        if (!is_string($value) || $value === '') {
            return;
        }

        // Accepts "/categories/<uuid>" as well as "<uuid>"
        $id = basename($value);
        $alias = $queryBuilder->getRootAliases()[0];
        $parameter = $queryNameGenerator->generateParameterName('category');
        $split = $queryNameGenerator->generateJoinAlias('split');

        if (!Uuid::isValid($id)) {
            $queryBuilder->andWhere('1 = 0'); // unknown category: nothing

            return;
        }

        $queryBuilder
            ->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s %s WHERE %2$s.transaction = %s AND IDENTITY(%2$s.category) = :%s)',
                TransactionSplit::class,
                $split,
                $alias,
                $parameter,
            ))
            ->setParameter($parameter, Uuid::fromString($id), UuidType::NAME);
    }
}
