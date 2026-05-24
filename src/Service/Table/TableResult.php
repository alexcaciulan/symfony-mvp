<?php

declare(strict_types=1);

namespace App\Service\Table;

/**
 * Library-agnostic result of a table query: the serialized rows of one page plus
 * the total row count. The Tabulator adapter shapes it into the grid's wire format.
 */
final readonly class TableResult
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $pageSize,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->pageSize)));
    }
}
