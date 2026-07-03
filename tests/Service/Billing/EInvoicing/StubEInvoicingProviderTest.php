<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing\EInvoicing;

use App\DTO\Billing\EInvoicing\EInvoiceIssueRequest;
use App\DTO\Billing\EInvoicing\EInvoiceLineInput;
use App\DTO\Billing\PartySnapshot;
use App\Enum\EInvoiceStatus;
use App\Service\Billing\EInvoicing\StubEInvoicingProvider;
use PHPUnit\Framework\TestCase;

class StubEInvoicingProviderTest extends TestCase
{
    private function request(string $key = 'abc'): EInvoiceIssueRequest
    {
        return new EInvoiceIssueRequest(
            supplierCif: 'RO1',
            seriesName: 'LEX',
            client: new PartySnapshot(name: 'Cabinet X', cui: 'RO2'),
            lines: [new EInvoiceLineInput('Abonament', '99.00', '1.000', '19')],
            idempotencyKey: $key,
        );
    }

    public function testIssueReturnsSeriesNumberDeterministically(): void
    {
        $provider = new StubEInvoicingProvider('dev');

        $a = $provider->issue($this->request('inv-1'));
        $b = $provider->issue($this->request('inv-1'));

        $this->assertSame('stub', $a->providerName);
        $this->assertSame('LEX', $a->series);
        $this->assertSame($a->number, $b->number, 'same idempotency key yields same number');
        $this->assertSame(EInvoiceStatus::NOT_APPLICABLE, $a->eInvoiceStatus);
    }

    public function testIssueThrowsInProduction(): void
    {
        $provider = new StubEInvoicingProvider('prod');

        $this->expectException(\LogicException::class);
        $provider->issue($this->request());
    }

    public function testGetNameIsStub(): void
    {
        $this->assertSame('stub', (new StubEInvoicingProvider('dev'))->getName());
    }
}
