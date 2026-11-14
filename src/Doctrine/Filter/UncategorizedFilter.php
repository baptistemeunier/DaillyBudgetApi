<?php

namespace App\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Operation;
use App\Repository\TransactionRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * ?uncategorized=true keeps the transactions not fully categorized (same rule as the user action counter).
 */
final class UncategorizedFilter implements FilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $value = $context['parameter']?->getValue();
        if (!filter_var($value, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $queryBuilder->andWhere(TransactionRepository::uncategorizedCondition($queryBuilder->getRootAliases()[0]));
    }
}
