<?php

declare(strict_types=1);

namespace App\Service\Table\Definition;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Repository\LegalCaseRepository;
use App\Service\Table\Column;
use App\Service\Table\Filter;
use App\Service\Table\TableDefinitionInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cases table definition: columns, decoupled toolbar filters (status multi-select
 * + search across the internal and court case numbers), and a user-scoped query builder.
 */
final class LegalCaseTableDefinition implements TableDefinitionInterface
{
    public function __construct(
        private readonly LegalCaseRepository $repository,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function key(): string
    {
        return 'cases';
    }

    public function getColumns(): array
    {
        return [
            new Column('caseNumber', 'table.cases.columns.number', sortable: true, width: 170),
            new Column('court', 'table.cases.columns.court'),
            new Column('amount', 'table.cases.columns.amount', sortable: true, width: 150),
            new Column('status', 'table.cases.columns.status', sortable: true, formatter: 'status_badge', width: 150),
            new Column('createdAt', 'table.cases.columns.date', sortable: true, formatter: 'datetime', width: 130),
            new Column('actions', 'table.cases.columns.actions', formatter: 'open_link', width: 120),
        ];
    }

    public function getFilters(): array
    {
        $statusOptions = array_map(
            static fn (CaseStatus $s): array => ['value' => $s->value, 'labelKey' => $s->label()],
            CaseStatus::cases(),
        );

        return [
            new Filter(
                key: 'status',
                labelKey: 'table.cases.columns.status',
                type: 'enum',
                field: 'status',
                options: $statusOptions,
                multiple: true,
                placeholderKey: 'component.data_table.filter.status_placeholder',
            ),
            new Filter(
                key: 'court',
                labelKey: 'table.cases.columns.court',
                type: 'autocomplete',
                field: 'court',
                placeholderKey: 'component.data_table.filter.court_placeholder',
                remoteRoute: 'api_courts_lookup',
            ),
            new Filter(
                key: 'creditor',
                labelKey: 'table.cases.filter.creditor_label',
                type: 'autocomplete',
                field: 'creditor',
                placeholderKey: 'component.data_table.filter.creditor_placeholder',
                remoteRoute: 'api_creditors_lookup',
            ),
            new Filter(
                key: 'search',
                labelKey: 'component.data_table.filter.search_label',
                type: 'search',
                searchFields: ['caseNumber', 'courtCaseNumber'],
                placeholderKey: 'component.data_table.filter.search_placeholder',
            ),
        ];
    }

    public function createScopedQueryBuilder(User $user): QueryBuilder
    {
        return $this->repository->createQueryBuilder('t')
            ->leftJoin('t.court', 'c')->addSelect('c')
            ->where('t.user = :user')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC');
    }

    public function serializeRow(object $row): array
    {
        if (!$row instanceof LegalCase) {
            throw new \UnexpectedValueException('Expected a LegalCase row.');
        }

        $status = $row->getStatus();
        $amount = $row->getAmount();

        return [
            'id' => $row->getId(),
            'caseNumber' => $row->getCourtCaseNumber() ?? $row->getCaseNumber(),
            'court' => $row->getCourt()?->getName() ?? '—',
            'amount' => $amount !== null ? number_format((float) $amount, 2, ',', '.') . ' ' . $row->getCurrency() : '—',
            'status' => [
                'value' => $status->value,
                'label' => $this->translator->trans($status->label()),
                'color' => $status->color(),
            ],
            'createdAt' => $row->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'link' => $this->urlGenerator->generate('case_overview', ['id' => $row->getId()]),
        ];
    }
}
