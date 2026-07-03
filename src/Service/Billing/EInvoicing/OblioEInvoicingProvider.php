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
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Oblio (https://www.oblio.eu/api) adapter. The provider owns numbering, VAT,
 * the official PDF and the e-Factura/SPV transmission; this adapter only maps
 * the normalized request to Oblio's payload and reads back the result.
 *
 * Auth is OAuth2 (token valid ~1h, cached). Issuance creates an already-collected
 * invoice and, when e-Factura is enabled for the account, sends it to SPV. SPV
 * status is later polled (Oblio webhooks do not cover SPV status).
 */
#[AutoconfigureTag('app.einvoicing_provider')]
final class OblioEInvoicingProvider implements EInvoicingProviderInterface
{
    private const BASE = 'https://www.oblio.eu/api';
    private const TOKEN_CACHE_KEY = 'oblio_access_token';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly string $oblioEmail,
        private readonly string $oblioSecret,
        private readonly string $oblioCif,
        private readonly bool $oblioEfacturaEnabled,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function getName(): string
    {
        return 'oblio';
    }

    public function issue(EInvoiceIssueRequest $request): EInvoiceIssueResult
    {
        $data = $this->post('/docs/invoice', $this->invoicePayload($request));

        $series = (string) ($data['seriesName'] ?? $request->seriesName);
        $number = (string) ($data['number'] ?? '');
        if ('' === $number) {
            throw EInvoicingException::business('Oblio returned no invoice number.');
        }

        $eInvoiceStatus = EInvoiceStatus::NOT_APPLICABLE;
        $spvId = null;
        if ($this->oblioEfacturaEnabled) {
            $status = $this->sendToSpv($series, $number);
            $eInvoiceStatus = $status->eInvoiceStatus;
            $spvId = $status->spvId;
        }

        return new EInvoiceIssueResult(
            providerName: $this->getName(),
            series: $series,
            number: $number,
            providerInvoiceId: $series . '/' . $number,
            eInvoiceStatus: $eInvoiceStatus,
            pdfUrl: $data['link'] ?? null,
            spvId: $spvId,
        );
    }

    public function fetchEInvoiceStatus(FiscalInvoice $invoice): EInvoiceStatusResult
    {
        if (null === $invoice->getSeries() || null === $invoice->getNumber()) {
            return new EInvoiceStatusResult(EInvoiceStatus::NOT_APPLICABLE);
        }

        $data = $this->get('/docs/einvoice', [
            'cif' => $this->oblioCif,
            'seriesName' => $invoice->getSeries(),
            'number' => $invoice->getNumber(),
        ]);

        return new EInvoiceStatusResult(
            eInvoiceStatus: $this->mapSpvCode($data['code'] ?? null),
            spvId: isset($data['spvId']) ? (string) $data['spvId'] : $invoice->getSpvId(),
            error: $data['error'] ?? null,
        );
    }

    public function storno(FiscalInvoice $invoice, string $reason): EInvoiceCancelResult
    {
        if (null === $invoice->getSeries() || null === $invoice->getNumber()) {
            return new EInvoiceCancelResult(success: false, error: 'Invoice not issued.');
        }

        // Real correction invoice: a new document referencing the original with
        // refund:1 (deletes the associated collection too).
        $payload = [
            'cif' => $this->oblioCif,
            'seriesName' => $invoice->getSeries(),
            'mentions' => $reason,
            'referenceDocument' => [
                'type' => 'Factura',
                'seriesName' => $invoice->getSeries(),
                'number' => $invoice->getNumber(),
                'refund' => 1,
            ],
        ];

        $data = $this->post('/docs/invoice', $payload);

        return new EInvoiceCancelResult(
            success: true,
            stornoSeries: (string) ($data['seriesName'] ?? $invoice->getSeries()),
            stornoNumber: isset($data['number']) ? (string) $data['number'] : null,
        );
    }

    public function getPdf(FiscalInvoice $invoice): ?string
    {
        $url = $invoice->getPdfUrl();
        if (null === $url) {
            return null;
        }

        try {
            return $this->http->request('GET', $url)->getContent();
        } catch (TransportExceptionInterface|HttpExceptionInterface $e) {
            $this->logger->warning('Oblio PDF fetch failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    public function isEFacturaEnabled(): bool
    {
        return $this->oblioEfacturaEnabled;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(EInvoiceIssueRequest $request): array
    {
        $client = $request->client;
        $payload = [
            'cif' => $this->oblioCif,
            'client' => array_filter([
                'cif' => $client->cui,
                'name' => $client->name,
                'rc' => $client->onrcNumber,
                'address' => $client->address,
                'email' => $client->email,
                'phone' => $client->phone,
                'vatPayer' => null !== $client->cui && str_starts_with($client->cui, 'RO') ? 1 : 0,
            ], static fn ($v) => null !== $v && '' !== $v),
            'seriesName' => $request->seriesName,
            'language' => $request->language,
            'currency' => $request->currency,
            'issueDate' => $request->issueDate?->format('Y-m-d'),
            'dueDate' => $request->dueDate?->format('Y-m-d'),
            'idempotencyKey' => $request->idempotencyKey,
            'products' => array_map($this->productPayload(...), $request->lines),
        ];

        if (null !== $request->collect) {
            $payload['collect'] = [
                'type' => $request->collect->type,
                'value' => $request->collect->value,
                'issueDate' => $request->collect->date->format('Y-m-d'),
            ];
        }

        return array_filter($payload, static fn ($v) => null !== $v);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(EInvoiceLineInput $line): array
    {
        return [
            'name' => $line->name,
            'price' => $line->unitPriceNet,
            'measuringUnit' => 'buc',
            'currency' => 'RON',
            'quantity' => $line->quantity,
            'vatName' => $line->vatName,
            'vatPercentage' => $line->vatPercentage,
            'vatIncluded' => 0,
        ];
    }

    private function sendToSpv(string $series, string $number): EInvoiceStatusResult
    {
        try {
            $data = $this->post('/docs/einvoice', [
                'cif' => $this->oblioCif,
                'seriesName' => $series,
                'number' => $number,
            ]);

            return new EInvoiceStatusResult(
                eInvoiceStatus: $this->mapSpvCode($data['code'] ?? 1),
                spvId: isset($data['spvId']) ? (string) $data['spvId'] : null,
            );
        } catch (EInvoicingException $e) {
            // Issuing succeeded; SPV send failed. Don't fail the whole issuance —
            // mark SENT-pending as ERROR so the reconcile/sync can retry the send.
            $this->logger->error('Oblio SPV send failed for {s}/{n}: {msg}', ['s' => $series, 'n' => $number, 'msg' => $e->getMessage()]);

            return new EInvoiceStatusResult(EInvoiceStatus::ERROR, error: $e->getMessage());
        }
    }

    private function mapSpvCode(mixed $code): EInvoiceStatus
    {
        return match ((int) $code) {
            1 => EInvoiceStatus::SENT,
            0, -1 => EInvoiceStatus::PENDING, // 0 = processing, -1 = queued, not yet transmitted
            2 => EInvoiceStatus::ERROR,
            default => EInvoiceStatus::NOT_APPLICABLE,
        };
    }

    private function token(): string
    {
        return $this->cache->get(self::TOKEN_CACHE_KEY, function (ItemInterface $item): string {
            $item->expiresAfter(3500);

            try {
                $response = $this->http->request('POST', self::BASE . '/authorize/token', [
                    'body' => ['client_id' => $this->oblioEmail, 'client_secret' => $this->oblioSecret],
                ]);
                $status = $response->getStatusCode();
                $data = $response->toArray(false);
            } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
                throw EInvoicingException::transient('Oblio auth transport error: ' . $e->getMessage(), $e);
            }

            if ($status >= 500 || 429 === $status) {
                throw EInvoicingException::transient('Oblio auth transient error (HTTP ' . $status . '): ' . ($data['statusMessage'] ?? $status));
            }

            $token = $data['access_token'] ?? null;
            if (!is_string($token) || '' === $token) {
                throw EInvoicingException::business('Oblio auth failed: no access_token in response.');
            }

            return $token;
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        try {
            $response = $this->http->request('POST', self::BASE . $path, [
                'auth_bearer' => $this->token(),
                'json' => $payload,
            ]);

            return $this->envelope($response->toArray(false), $response->getStatusCode());
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            throw EInvoicingException::transient('Oblio transport error on ' . $path . ': ' . $e->getMessage(), $e);
        }
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        try {
            $response = $this->http->request('GET', self::BASE . $path, [
                'auth_bearer' => $this->token(),
                'query' => $query,
            ]);

            return $this->envelope($response->toArray(false), $response->getStatusCode());
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            throw EInvoicingException::transient('Oblio transport error on ' . $path . ': ' . $e->getMessage(), $e);
        }
    }

    /**
     * Unwrap Oblio's `{status, statusMessage, data}` envelope and classify errors.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function envelope(array $body, int $httpStatus): array
    {
        $status = (int) ($body['status'] ?? $httpStatus);
        // 5xx and 429 (rate limit: 30 docs/100s) are transient → Messenger retries
        // with backoff. Everything else non-2xx is a business error (no retry).
        if ($status >= 500 || 429 === $status) {
            throw EInvoicingException::transient('Oblio transient error (HTTP ' . $status . '): ' . ($body['statusMessage'] ?? $status));
        }
        if ($status < 200 || $status >= 300) {
            throw EInvoicingException::business('Oblio error: ' . ($body['statusMessage'] ?? ('HTTP ' . $status)));
        }

        $data = $body['data'] ?? [];

        return is_array($data) ? $data : [];
    }
}
