<?php

declare(strict_types=1);

namespace App\Service\Table;

/**
 * Normalized, library-agnostic table criteria (page, size, sort, filters). The
 * Tabulator adapter produces it from the HTTP request; the engine consumes it.
 * Swapping the grid library only changes the adapter, never this contract.
 */
final readonly class TableQuery
{
    /**
     * @param 'ASC'|'DESC'         $sortDir
     * @param array<string, mixed> $filters field name => raw filter value
     */
    public function __construct(
        public int $page = 1,
        public int $pageSize = 25,
        public ?string $sortField = null,
        public string $sortDir = 'ASC',
        public array $filters = [],
    ) {}
}
