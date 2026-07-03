<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing\EInvoicing;

use App\DTO\Billing\EInvoicing\EInvoiceCollect;
use App\DTO\Billing\EInvoicing\EInvoiceIssueRequest;
use App\DTO\Billing\EInvoicing\EInvoiceLineInput;
use App\DTO\Billing\PartySnapshot;
use App\Enum\EInvoiceStatus;
use App\Service\Billing\EInvoicing\EInvoicingException;
use App\Service\Billing\EInvoicing\OblioEInvoicingProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OblioEInvoicingProviderTest extends TestCase
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
            collect: new EInvoiceCollect('Card', '117.81', new \DateTimeImmutable('2026-07-01')),
        );
    }

    private function provider(MockHttpClient $http, bool $efactura = true): OblioEInvoicingProvider
    {
        return new OblioEInvoicingProvider($http, new ArrayAdapter(), 'me@firm.ro', 'secret', 'RO123', $efactura);
    }

    public function testIssueHappyPathMapsSeriesNumberLinkAndSpvStatus(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600]), ['http_code' => 200]),
            new MockResponse(json_encode(['status' => 200, 'data' => ['seriesName' => 'FCT', 'number' => '0053', 'link' => 'https://www.oblio.eu/pdf/1']]), ['http_code' => 200]),
            new MockResponse(json_encode(['status' => 200, 'data' => ['code' => 1, 'spvId' => 'spv-1']]), ['http_code' => 200]),
        ]);

        $result = $this->provider($http)->issue($this->request());

        $this->assertSame('oblio', $result->providerName);
        $this->assertSame('FCT', $result->series);
        $this->assertSame('0053', $result->number);
        $this->assertSame('FCT/0053', $result->providerInvoiceId);
        $this->assertSame('https://www.oblio.eu/pdf/1', $result->pdfUrl);
        $this->assertSame(EInvoiceStatus::SENT, $result->eInvoiceStatus);
        $this->assertSame('spv-1', $result->spvId);
    }

    public function testIssueWithoutEfacturaSkipsSpvSend(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok']), ['http_code' => 200]),
            new MockResponse(json_encode(['status' => 200, 'data' => ['seriesName' => 'FCT', 'number' => '0054', 'link' => 'u']]), ['http_code' => 200]),
        ]);

        $result = $this->provider($http, efactura: false)->issue($this->request());

        $this->assertSame('0054', $result->number);
        $this->assertSame(EInvoiceStatus::NOT_APPLICABLE, $result->eInvoiceStatus);
    }

    public function testBusinessErrorOn4xx(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok']), ['http_code' => 200]),
            new MockResponse(json_encode(['status' => 400, 'statusMessage' => 'Invalid CIF']), ['http_code' => 400]),
        ]);

        $this->expectException(EInvoicingException::class);
        try {
            $this->provider($http)->issue($this->request());
        } catch (EInvoicingException $e) {
            $this->assertFalse($e->retryable);
            throw $e;
        }
    }

    public function testRateLimit429IsTransient(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok']), ['http_code' => 200]),
            new MockResponse(json_encode(['status' => 429, 'statusMessage' => 'rate limited']), ['http_code' => 429]),
        ]);

        try {
            $this->provider($http)->issue($this->request());
            $this->fail('expected EInvoicingException');
        } catch (EInvoicingException $e) {
            $this->assertTrue($e->retryable, '429 must be retryable (rate limit)');
        }
    }

    public function testTransientErrorOn5xx(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok']), ['http_code' => 200]),
            new MockResponse(json_encode(['status' => 500, 'statusMessage' => 'oops']), ['http_code' => 500]),
        ]);

        try {
            $this->provider($http)->issue($this->request());
            $this->fail('expected EInvoicingException');
        } catch (EInvoicingException $e) {
            $this->assertTrue($e->retryable);
        }
    }
}
