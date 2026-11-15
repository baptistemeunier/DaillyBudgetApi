<?php

namespace App\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * ?read=false keeps the unread notifications (readAt null), ?read=true the read ones.
 */
final class ReadFilter implements FilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $read = filter_var($context['parameter']?->getValue(), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if (null === $read) {
            return;
        }

        $queryBuilder->andWhere(sprintf('%s.readAt IS %s', $queryBuilder->getRootAliases()[0], $read ? 'NOT NULL' : 'NULL'));
    }
}
