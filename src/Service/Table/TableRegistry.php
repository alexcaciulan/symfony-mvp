<?php

declare(strict_types=1);

namespace App\Service\Table;

/**
 * Resolves a {@see TableDefinitionInterface} by its key. Definitions are
 * collected through the `app.table_definition` tagged iterator, mirroring the
 * extraction strategy registry.
 */
final class TableRegistry
{
    /** @var array<string, TableDefinitionInterface> */
    private array $byKey = [];

    /**
     * @param iterable<TableDefinitionInterface> $definitions
     */
    public function __construct(iterable $definitions)
    {
        foreach ($definitions as $definition) {
            $this->byKey[$definition->key()] = $definition;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    public function get(string $key): TableDefinitionInterface
    {
        return $this->byKey[$key]
            ?? throw new \InvalidArgumentException(sprintf('No table definition registered for key "%s".', $key));
    }
}
