<?php

declare(strict_types=1);

namespace App\Service\Table\Definition;

use App\Entity\FiscalInvoice;
use App\Entity\User;
use App\Enum\EInvoiceStatus;
use App\Repository\FiscalInvoiceRepository;
use App\Service\Table\Column;
use App\Service\Table\Filter;
use App\Service\Table\TableDefinitionInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fiscal invoices table (the client's real invoices, mirrored from the provider):
 * e-Factura status + date-range filters, user-scoped, with a link to the invoice
 * detail (PDF download from there).
 */
final class FiscalInvoiceTableDefinition implements TableDefinitionInterface
{
    public function __construct(
        private readonly FiscalInvoiceRepository $repository,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function key(): string
    {
        return 'fiscal_invoices';
    }

    public function getColumns(): array
    {
        return [
            new Column('number', 'table.fiscal_invoices.columns.number', sortable: true, width: 140),
            new Column('issuedAt', 'table.fiscal_invoices.columns.date', sortable: true, formatter: 'datetime', width: 140),
            new Column('grossTotal', 'table.fiscal_invoices.columns.total', sortable: true, width: 140),
            new Column('eInvoiceStatus', 'table.fiscal_invoices.columns.einvoice', sortable: true, formatter: 'status_badge', width: 160),
            new Column('actions', 'table.fiscal_invoices.columns.actions', formatter: 'open_link', width: 130),
        ];
    }

    public function getFilters(): array
    {
        return [
            new Filter(
                key: 'eInvoiceStatus',
                labelKey: 'table.fiscal_invoices.columns.einvoice',
                type: 'enum',
                field: 'eInvoiceStatus',
                options: array_map(
                    static fn (EInvoiceStatus $s): array => ['value' => $s->value, 'labelKey' => $s->label()],
                    EInvoiceStatus::cases(),
                ),
                multiple: true,
                placeholderKey: 'table.fiscal_invoices.filters.einvoice_placeholder',
            ),
            new Filter(
                key: 'period',
                labelKey: 'table.fiscal_invoices.filters.period',
                type: 'date_range',
                field: 'issuedAt',
            ),
        ];
    }

    public function createScopedQueryBuilder(User $user): QueryBuilder
    {
        return $this->repository->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.number IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('t.issuedAt', 'DESC');
    }

    public function serializeRow(object $row): array
    {
        if (!$row instanceof FiscalInvoice) {
            throw new \UnexpectedValueException('Expected a FiscalInvoice row.');
        }

        $status = $row->getEInvoiceStatus();

        return [
            'id' => $row->getId(),
            'number' => $row->getFormattedNumber() ?? '—',
            'issuedAt' => $row->getIssuedAt()?->format(\DateTimeInterface::ATOM),
            'grossTotal' => number_format((float) $row->getGrossTotal(), 2, ',', '.') . ' ' . $row->getCurrency(),
            'eInvoiceStatus' => [
                'value' => $status->value,
                'label' => $this->translator->trans($status->label()),
                'color' => $status->color(),
            ],
            'link' => $this->urlGenerator->generate('app_fiscal_invoice_show', ['id' => $row->getId()]),
        ];
    }
}
