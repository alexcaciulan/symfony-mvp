<?php

declare(strict_types=1);

namespace App\Controller\Admin\Filter;

use Doctrine\ORM\QueryBuilder;

/** One shared join to the court, whichever of the court filters runs first. */
final class CourtJoin
{
    private const ALIAS = 'filter_court';

    public static function alias(QueryBuilder $queryBuilder, string $rootAlias): string
    {
        if (!in_array(self::ALIAS, $queryBuilder->getAllAliases(), true)) {
            $queryBuilder->leftJoin($rootAlias . '.court', self::ALIAS);
        }

        return self::ALIAS;
    }
}
