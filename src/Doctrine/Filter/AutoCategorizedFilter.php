<?php

namespace App\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Operation;
use App\Enum\SplitSource;
use App\Repository\TransactionRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * ?autoCategorized=true keeps the transactions categorized by a rule, to be reviewed (same rule as the user action counter).
 */
final class AutoCategorizedFilter implements FilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $value = $context['parameter']?->getValue();
        if (!filter_var($value, FILTER_VALIDATE_BOOL)) {
            return;
        }

        $parameter = $queryNameGenerator->generateParameterName('auto_source');

        $queryBuilder
            ->andWhere(TransactionRepository::autoCategorizedCondition($queryBuilder->getRootAliases()[0], $parameter))
            ->setParameter($parameter, SplitSource::RULE);
    }
}
