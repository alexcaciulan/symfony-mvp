<?php

declare(strict_types=1);

namespace App\Service\Table;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;

/**
 * Per-entity table description. Implementations are auto-registered (tagged
 * `app.table_definition`) and looked up by {@see TableRegistry}. Adding a new
 * table to the app means implementing this interface, nothing else.
 */
interface TableDefinitionInterface
{
    /** Stable URL-safe key used in the generic data endpoint and `<twig:DataTable key>`. */
    public function key(): string;

    /** @return Column[] */
    public function getColumns(): array;

    /**
     * Toolbar filters decoupled from columns (status, search, ...). Return an
     * empty array to rely solely on per-column header filters.
     *
     * @return Filter[]
     */
    public function getFilters(): array;

    /**
     * Base query already scoped to the current user (and any soft-delete filter).
     * Must use the root alias `t` so the engine can apply sort/filter on `t.<field>`.
     */
    public function createScopedQueryBuilder(User $user): QueryBuilder;

    /**
     * Map one entity to its row payload, keyed by column name. Values may be
     * scalars or structured (e.g. ['value' => ..., 'label' => ..., 'color' => ...])
     * for frontend formatters such as status_badge.
     *
     * @return array<string, mixed>
     */
    public function serializeRow(object $row): array;
}
