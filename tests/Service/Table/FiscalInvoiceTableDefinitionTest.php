<?php

declare(strict_types=1);

namespace App\Tests\Service\Table;

use App\Entity\FiscalInvoice;
use App\Entity\User;
use App\Enum\EInvoiceStatus;
use App\Repository\FiscalInvoiceRepository;
use App\Service\Table\Definition\FiscalInvoiceTableDefinition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class FiscalInvoiceTableDefinitionTest extends KernelTestCase
{
    private FiscalInvoiceTableDefinition $definition;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->definition = new FiscalInvoiceTableDefinition(
            $c->get(FiscalInvoiceRepository::class),
            $c->get(TranslatorInterface::class),
            $c->get(UrlGeneratorInterface::class),
        );
    }

    public function testDeclaresColumnsAndFilters(): void
    {
        $this->assertSame('fiscal_invoices', $this->definition->key());

        $cols = array_map(static fn ($c) => $c->name, $this->definition->getColumns());
        $this->assertSame(['number', 'issuedAt', 'grossTotal', 'eInvoiceStatus', 'actions'], $cols);

        $filterTypes = array_map(static fn ($f) => $f->type, $this->definition->getFilters());
        $this->assertContains('date_range', $filterTypes);
        $this->assertContains('enum', $filterTypes);
    }

    public function testSerializeRowFormatsNumberBadgeAndLink(): void
    {
        $fiscal = (new FiscalInvoice())
            ->setUser(new User())
            ->setSeries('TES')->setNumber('0046')
            ->setIssuedAt(new \DateTimeImmutable('2026-06-18'))
            ->setGrossTotal('253.27')
            ->setEInvoiceStatus(EInvoiceStatus::SENT);
        (new \ReflectionProperty(FiscalInvoice::class, 'id'))->setValue($fiscal, 7);

        $row = $this->definition->serializeRow($fiscal);

        $this->assertSame('TES 0046', $row['number']);
        $this->assertSame('253,27 RON', $row['grossTotal']);
        $this->assertSame('sent', $row['eInvoiceStatus']['value']);
        $this->assertSame('blue', $row['eInvoiceStatus']['color']);
        $this->assertStringContainsString('/subscription/fiscal-invoices/7', $row['link']);
    }
}
