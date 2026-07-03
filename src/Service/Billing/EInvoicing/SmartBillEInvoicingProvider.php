<?php

declare(strict_types=1);

namespace App\Service\Billing\EInvoicing;

use App\DTO\Billing\EInvoicing\EInvoiceCancelResult;
use App\DTO\Billing\EInvoicing\EInvoiceIssueRequest;
use App\DTO\Billing\EInvoicing\EInvoiceIssueResult;
use App\DTO\Billing\EInvoicing\EInvoiceLineInput;
use App\DTO\Billing\EInvoicing\EInvoiceStatusResult;
use App\Entity\FiscalInvoice;
use App\Enum\EInvoiceStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SmartBill Cloud (https://ws.smartbill.ro/SBORO/api) adapter. Basic auth
 * (email:token). The provider owns numbering/VAT/PDF and, when the e-Factura
 * module is active on the account, the SPV transmission.
 *
 * NOTE: SmartBill's official API portal is JS-rendered; the create payload and
 * the PDF/cancel endpoints below are confirmed from community wrappers, but the
 * exact collect (payment) and SPV-status paths must be confirmed against the
 * official docs before relying on this adapter in production. Oblio is the
 * primary, fully-verified adapter.
 */
#[AutoconfigureTag('app.einvoicing_provider')]
final class SmartBillEInvoicingProvider implements EInvoicingProviderInterface
{
    private const BASE = 'https://ws.smartbill.ro/SBORO/api';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $smartbillEmail,
        private readonly string $smartbillToken,
        private readonly string $smartbillCif,
        private readonly bool $smartbillEfacturaEnabled,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function getName(): string
    {
        return 'smartbill';
    }

    public function issue(EInvoiceIssueRequest $request): EInvoiceIssueResult
    {
        $data = $this->request('POST', '/invoice', ['json' => $this->invoicePayload($request)]);

        $number = (string) ($data['number'] ?? '');
        $series = (string) ($data['series'] ?? $request->seriesName);
        if ('' === $number) {
            throw EInvoicingException::business('SmartBill returned no invoice number.');
        }

        return new EInvoiceIssueResult(
            providerName: $this->getName(),
            series: $series,
            number: $number,
            providerInvoiceId: $series . '/' . $number,
            // SmartBill does not confirm the SPV status at create time; if the
            // e-Factura module is on, the real status starts PENDING (queued) and
            // is corrected by the P3 status poll.
            eInvoiceStatus: $this->smartbillEfacturaEnabled ? EInvoiceStatus::PENDING : EInvoiceStatus::NOT_APPLICABLE,
            pdfUrl: $data['url'] ?? null,
        );
    }

    public function fetchEInvoiceStatus(FiscalInvoice $invoice): EInvoiceStatusResult
    {
        // SPV status polling path to be confirmed against the official docs.
        // Conservative default: keep the last known status.
        return new EInvoiceStatusResult($invoice->getEInvoiceStatus(), $invoice->getSpvId());
    }

    public function storno(FiscalInvoice $invoice, string $reason): EInvoiceCancelResult
    {
        if (null === $invoice->getSeries() || null === $invoice->getNumber()) {
            return new EInvoiceCancelResult(success: false, error: 'Invoice not issued.');
        }

        // /invoice/cancel only works before the invoice reached SPV. A real
        // post-SPV storno (correction invoice) is P3 scope.
        if (EInvoiceStatus::NOT_APPLICABLE !== $invoice->getEInvoiceStatus()) {
            throw EInvoicingException::business('SmartBill cancel is pre-SPV only; post-SPV storno not yet implemented.');
        }

        $this->request('PUT', '/invoice/cancel', ['query' => [
            'cif' => $this->smartbillCif,
            'seriesname' => $invoice->getSeries(),
            'number' => $invoice->getNumber(),
        ]]);

        return new EInvoiceCancelResult(success: true);
    }

    public function getPdf(FiscalInvoice $invoice): ?string
    {
        if (null === $invoice->getSeries() || null === $invoice->getNumber()) {
            return null;
        }

        try {
            $response = $this->http->request('GET', self::BASE . '/invoice/pdf', [
                'auth_basic' => [$this->smartbillEmail, $this->smartbillToken],
                'headers' => ['Accept' => 'application/octet-stream'],
                'query' => [
                    'cif' => $this->smartbillCif,
                    'seriesname' => $invoice->getSeries(),
                    'number' => $invoice->getNumber(),
                ],
            ]);

            return 200 === $response->getStatusCode() ? $response->getContent(false) : null;
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('SmartBill PDF fetch failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    public function isEFacturaEnabled(): bool
    {
        return $this->smartbillEfacturaEnabled;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(EInvoiceIssueRequest $request): array
    {
        $client = $request->client;
        $isVatPayer = null !== $client->cui && str_starts_with($client->cui, 'RO');

        return array_filter([
            'companyVatCode' => $this->smartbillCif,
            'client' => array_filter([
                'name' => $client->name,
                'vatCode' => $client->cui,
                'isTaxPayer' => $isVatPayer,
                'address' => $client->address,
                'country' => $client->country,
                'email' => $client->email,
                'saveToDb' => false,
            ], static fn ($v) => null !== $v && '' !== $v),
            'issueDate' => $request->issueDate?->format('Y-m-d'),
            'dueDate' => $request->dueDate?->format('Y-m-d'),
            'seriesName' => $request->seriesName,
            'currency' => $request->currency,
            'language' => $request->language,
            'isDraft' => false,
            'products' => array_map($this->productPayload(...), $request->lines),
        ], static fn ($v) => null !== $v);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(EInvoiceLineInput $line): array
    {
        return [
            'name' => $line->name,
            'measuringUnitName' => 'buc',
            'currency' => 'RON',
            'quantity' => (float) $line->quantity,
            'price' => (float) $line->unitPriceNet,
            'isTaxIncluded' => false,
            'taxName' => $line->vatName,
            'taxPercentage' => (float) $line->vatPercentage,
            'isService' => true,
            'saveToDb' => false,
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        try {
            $response = $this->http->request($method, self::BASE . $path, array_merge($options, [
                'auth_basic' => [$this->smartbillEmail, $this->smartbillToken],
            ]));
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            throw EInvoicingException::transient('SmartBill transport error on ' . $path . ': ' . $e->getMessage(), $e);
        }

        if ($status >= 500 || 429 === $status) {
            throw EInvoicingException::transient('SmartBill transient error on ' . $path . ' (HTTP ' . $status . ').');
        }
        $errorText = $body['errorText'] ?? null;
        if ($status >= 400 || (is_string($errorText) && '' !== $errorText)) {
            throw EInvoicingException::business('SmartBill error on ' . $path . ': ' . ($errorText ?? ('HTTP ' . $status)));
        }

        return $body;
    }
}
