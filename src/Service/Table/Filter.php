<?php

declare(strict_types=1);

namespace App\Service\Table;

/**
 * Declarative description of a toolbar filter, decoupled from displayed columns.
 * Lets a table filter by fields it does not show as columns (e.g. a multi-field
 * search or a status multi-select). Rendered as an external toolbar above the
 * grid; whitelisted + applied by {@see TableDataService}.
 */
final readonly class Filter
{
    /**
     * @param 'search'|'enum'|'text'|'bool'|'autocomplete'|'date_range' $type
     * @param list<string>                                 $searchFields entity fields OR-ed for `search`
     * @param list<array{value: string, labelKey: string}> $options      for `enum`
     * @param ?string                                      $remoteRoute  route name for `autocomplete` (resolved to URL in {@see TableExtension})
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $type,
        public string $field = '',
        public array $searchFields = [],
        public array $options = [],
        public bool $multiple = false,
        public ?string $placeholderKey = null,
        public ?string $remoteRoute = null,
    ) {}

    public function resolvedField(): string
    {
        return $this->field !== '' ? $this->field : $this->key;
    }
}
