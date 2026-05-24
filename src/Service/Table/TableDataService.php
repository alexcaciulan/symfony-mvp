<?php

declare(strict_types=1);

namespace App\Service\Table;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Library-agnostic engine: turns a {@see TableQuery} into a {@see TableResult}
 * for a given {@see TableDefinitionInterface}. Sort and filters are whitelisted
 * against the definition's columns (anti SQL-injection), page size is clamped,
 * and rows are scoped to the user via the definition's query builder.
 *
 * The filter/sort logic lives in {@see self::applyCriteria()} so a future export
 * (an unpaginated `queryAll`) can reuse identical criteria handling.
 */
final class TableDataService
{
    /** @var list<int> */
    public const PAGE_SIZES = [10, 25, 50, 100];
    public const DEFAULT_PAGE_SIZE = 25;

    public function query(TableDefinitionInterface $definition, User $user, TableQuery $query): TableResult
    {
        $columns = $this->indexColumns($definition);
        $qb = $definition->createScopedQueryBuilder($user);
        $this->applyCriteria($qb, $columns, $query);

        $page = max(1, $query->page);
        $pageSize = in_array($query->pageSize, self::PAGE_SIZES, true) ? $query->pageSize : self::DEFAULT_PAGE_SIZE;

        $qb->setFirstResult(($page - 1) * $pageSize)->setMaxResults($pageSize);

        $paginator = new Paginator($qb, fetchJoinCollection: false);
        $total = count($paginator);

        $rows = [];
        foreach ($paginator as $entity) {
            $rows[] = $definition->serializeRow($entity);
        }

        return new TableResult($rows, $total, $page, $pageSize);
    }

    /**
     * Applies whitelisted sorting + filtering on root alias `t`. Shared seam for
     * pagination and (future) full export.
     *
     * @param array<string, Column> $columns
     */
    private function applyCriteria(QueryBuilder $qb, array $columns, TableQuery $query): void
    {
        if ($query->sortField !== null
            && isset($columns[$query->sortField])
            && $columns[$query->sortField]->sortable
        ) {
            $direction = strtoupper($query->sortDir) === 'DESC' ? 'DESC' : 'ASC';
            $qb->orderBy('t.' . $query->sortField, $direction);
        }

        $i = 0;
        foreach ($query->filters as $field => $value) {
            if (!isset($columns[$field]) || !$columns[$field]->filterable || $value === null || $value === '') {
                continue;
            }

            $param = 'tf' . $i++;
            match ($columns[$field]->filterType) {
                // Escape LIKE metacharacters (\ % _) so user input matches literally.
                'text' => $qb->andWhere("t.$field LIKE :$param")->setParameter($param, '%' . addcslashes((string) $value, '\\%_') . '%'),
                'bool' => $qb->andWhere("t.$field = :$param")->setParameter($param, filter_var($value, \FILTER_VALIDATE_BOOLEAN)),
                default => $qb->andWhere("t.$field = :$param")->setParameter($param, $value),
            };
        }
    }

    /**
     * @return array<string, Column>
     */
    private function indexColumns(TableDefinitionInterface $definition): array
    {
        $indexed = [];
        foreach ($definition->getColumns() as $column) {
            $indexed[$column->name] = $column;
        }

        return $indexed;
    }
}
