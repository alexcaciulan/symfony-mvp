<?php

declare(strict_types=1);

namespace App\Service\Table\Definition;

use App\Entity\Creditor;
use App\Entity\User;
use App\Enum\PersonType;
use App\Repository\CreditorRepository;
use App\Service\Table\Column;
use App\Service\Table\Filter;
use App\Service\Table\TableDefinitionInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creditors table: the lawyer's reusable creditor library, rendered with the
 * same Tabulator grid as the cases table (search + person-type filter +
 * pagination). The "open" action points at the creditor edit page.
 */
final class CreditorTableDefinition implements TableDefinitionInterface
{
    public function __construct(
        private readonly CreditorRepository $repository,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function key(): string
    {
        return 'creditors';
    }

    public function getColumns(): array
    {
        return [
            new Column('name', 'table.creditors.columns.name', sortable: true),
            new Column('personType', 'table.creditors.columns.person_type', width: 130),
            new Column('cui', 'table.creditors.columns.cui', sortable: true, width: 170),
            new Column('casesCount', 'table.creditors.columns.cases', width: 110),
            new Column('createdAt', 'table.creditors.columns.date', sortable: true, formatter: 'datetime', width: 130),
            new Column('actions', 'table.creditors.columns.actions', formatter: 'open_link', width: 120),
        ];
    }

    public function getFilters(): array
    {
        $personTypeOptions = array_map(
            static fn (PersonType $t): array => ['value' => $t->value, 'labelKey' => $t->label()],
            PersonType::cases(),
        );

        return [
            new Filter(
                key: 'personType',
                labelKey: 'table.creditors.columns.person_type',
                type: 'enum',
                field: 'personType',
                options: $personTypeOptions,
                multiple: true,
                placeholderKey: 'table.creditors.filter.person_type_placeholder',
            ),
            new Filter(
                key: 'search',
                labelKey: 'component.data_table.filter.search_label',
                type: 'search',
                searchFields: ['name', 'cui'],
                placeholderKey: 'table.creditors.filter.search_placeholder',
            ),
        ];
    }

    public function createScopedQueryBuilder(User $user): QueryBuilder
    {
        return $this->repository->createQueryBuilder('t')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.name', 'ASC');
    }

    public function serializeRow(object $row): array
    {
        if (!$row instanceof Creditor) {
            throw new \UnexpectedValueException('Expected a Creditor row.');
        }

        return [
            'id' => $row->getId(),
            'name' => $row->getName(),
            'personType' => $this->translator->trans($row->getPersonType()->label()),
            'cui' => $row->getCui() ?? $row->getPersonalId() ?? '—',
            'casesCount' => $row->getLegalCases()->count(),
            'createdAt' => $row->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'link' => $this->urlGenerator->generate('app_creditors_edit', ['id' => $row->getId()]),
        ];
    }
}
