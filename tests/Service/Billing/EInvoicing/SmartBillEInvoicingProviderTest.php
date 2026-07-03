<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing\EInvoicing;

use App\DTO\Billing\EInvoicing\EInvoiceIssueRequest;
use App\DTO\Billing\EInvoicing\EInvoiceLineInput;
use App\DTO\Billing\PartySnapshot;
use App\Enum\EInvoiceStatus;
use App\Service\Billing\EInvoicing\EInvoicingException;
use App\Service\Billing\EInvoicing\SmartBillEInvoicingProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SmartBillEInvoicingProviderTest extends TestCase
{
    private function request(): EInvoiceIssueRequest
    {
        return new EInvoiceIssueRequest(
            supplierCif: 'RO123',
            seriesName: 'FCT',
            client: new PartySnapshot(name: 'Cabinet X', cui: 'RO99', address: 'Cluj'),
            lines: [new EInvoiceLineInput('Abonament', '99.00', '1.000', '19')],
            idempotencyKey: '5',
            issueDate: new \DateTimeImmutable('2026-07-01'),
        );
    }

    private function provider(MockHttpClient $http, bool $efactura = true): SmartBillEInvoicingProvider
    {
        return new SmartBillEInvoicingProvider($http, 'me@firm.ro', 'token', 'RO123', $efactura);
    }

    public function testIssueHappyPathMapsSeriesNumber(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['series' => 'FCT', 'number' => '0005', 'url' => 'https://smartbill/pdf']), ['http_code' => 200]),
        ]);

        $result = $this->provider($http)->issue($this->request());

        $this->assertSame('smartbill', $result->providerName);
        $this->assertSame('FCT', $result->series);
        $this->assertSame('0005', $result->number);
        $this->assertSame('FCT/0005', $result->providerInvoiceId);
        $this->assertSame(EInvoiceStatus::PENDING, $result->eInvoiceStatus);
    }

    public function testBusinessErrorOnErrorText(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['errorText' => 'CIF invalid']), ['http_code' => 200]),
        ]);

        try {
            $this->provider($http)->issue($this->request());
            $this->fail('expected EInvoicingException');
        } catch (EInvoicingException $e) {
            $this->assertFalse($e->retryable);
        }
    }

    public function testBusinessErrorOn4xx(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['errorText' => 'bad request']), ['http_code' => 400]),
        ]);

        $this->expectException(EInvoicingException::class);
        $this->provider($http)->issue($this->request());
    }

    public function testTransientErrorOn5xx(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
        ]);

        try {
            $this->provider($http)->issue($this->request());
            $this->fail('expected EInvoicingException');
        } catch (EInvoicingException $e) {
            $this->assertTrue($e->retryable);
        }
    }
}
