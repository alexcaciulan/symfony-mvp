<?php

declare(strict_types=1);

namespace App\Service\Table;

/**
 * Declarative description of one table column. Defined once per table in a
 * {@see TableDefinitionInterface} and consumed by both the row serializer and
 * the frontend config (single source of truth).
 */
final readonly class Column
{
    /**
     * @param string      $name       entity field name and row key (mapped on alias `t`)
     * @param string      $labelKey   i18n key for the header
     * @param string|null $filterType 'text' | 'bool' | 'enum' | 'date' (null when not filterable)
     * @param string      $formatter  frontend formatter key (text | date | datetime | bool | status_badge)
     */
    public function __construct(
        public string $name,
        public string $labelKey,
        public bool $sortable = false,
        public bool $filterable = false,
        public ?string $filterType = null,
        public string $formatter = 'text',
        public ?int $width = null,
    ) {}
}
