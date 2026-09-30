<?php

declare(strict_types=1);

namespace App\Service\Table\Definition;

use App\Entity\Debtor;
use App\Entity\User;
use App\Repository\DebtorRepository;
use App\Service\Table\Column;
use App\Service\Table\Filter;
use App\Service\Table\TableDefinitionInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Debtors table: the lawyer's debtor library, on the same Tabulator grid as the
 * creditors. The "open" action points at the edit page.
 */
final class DebtorTableDefinition implements TableDefinitionInterface
{
    public function __construct(
        private readonly DebtorRepository $repository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function key(): string
    {
        return 'debtors';
    }

    public function getColumns(): array
    {
        return [
            new Column('name', 'table.debtors.columns.name', sortable: true),
            new Column('cui', 'table.debtors.columns.cui', sortable: true, width: 170),
            new Column('county', 'table.debtors.columns.county', width: 150),
            new Column('casesCount', 'table.debtors.columns.cases', width: 110),
            new Column('updatedAt', 'table.debtors.columns.date', sortable: true, formatter: 'datetime', width: 130),
            new Column('actions', 'table.debtors.columns.actions', formatter: 'open_link', width: 120),
        ];
    }

    public function getFilters(): array
    {
        return [
            new Filter(
                key: 'search',
                labelKey: 'component.data_table.filter.search_label',
                type: 'search',
                searchFields: ['name', 'cui'],
                placeholderKey: 'table.debtors.filter.search_placeholder',
            ),
        ];
    }

    public function createScopedQueryBuilder(User $user): QueryBuilder
    {
        return $this->repository->createLibraryQueryBuilder($user)->orderBy('d.name', 'ASC');
    }

    public function serializeRow(object $row): array
    {
        if (!$row instanceof Debtor) {
            throw new \UnexpectedValueException('Expected a Debtor row.');
        }

        return [
            'id' => $row->getId(),
            'name' => $row->getName(),
            'cui' => $row->getCui() ?? '—',
            'county' => $row->getAddressCounty() ?? '—',
            'casesCount' => $this->repository->countActiveCases($row),
            'updatedAt' => $row->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'link' => $this->urlGenerator->generate('app_debtors_edit', ['id' => $row->getId()]),
        ];
    }
}
