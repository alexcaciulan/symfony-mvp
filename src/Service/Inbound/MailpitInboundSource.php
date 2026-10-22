<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Dev source: the local Mailpit's API. It reads what reached the platform mailbox
 * and the VERP bounce addresses, never by sender (our own court emails leave from
 * the platform address and must not be read back), and tags what it took as
 * processed. Only the Mailpit host named in the DSN is ever contacted.
 */
final class MailpitInboundSource implements InboundMailSourceInterface
{
    public const NAME = 'mailpit';
    public const TAG = 'processed';

    /** Mailpit runs next to the app in dev and demo, never anywhere else. */
    private const ALLOWED_HOSTS = ['mailer', 'localhost', '127.0.0.1'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $platformAddress,
        private readonly string $bounceDomain,
        private readonly LoggerInterface $logger,
    ) {}

    public static function fromDsn(string $dsn, HttpClientInterface $httpClient, string $platformAddress, string $bounceDomain, LoggerInterface $logger): self
    {
        $parts = parse_url(str_replace('mailpit://', 'http://', $dsn));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new \InvalidArgumentException(sprintf('Mailpit host "%s" is not a local Mailpit.', $host));
        }
        $port = (int) ($parts['port'] ?? 8025);

        return new self($httpClient, sprintf('http://%s:%d', $host, $port), mb_strtolower(trim($platformAddress)), mb_strtolower(trim($bounceDomain)), $logger);
    }

    public function fetch(int $limit): iterable
    {
        $seen = [];
        $out = [];
        foreach ($this->queries() as $query) {
            foreach ($this->search($query, $limit) as $summary) {
                $id = (string) ($summary['ID'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $raw = $this->raw($id);
                if ($raw === null) {
                    continue;
                }
                $created = isset($summary['Created']) ? new \DateTimeImmutable((string) $summary['Created']) : new \DateTimeImmutable();
                $out[] = new RawInboundMail(self::NAME, $id, $raw, $created);
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    public function acknowledge(RawInboundMail $mail): void
    {
        $this->httpClient->request('PUT', $this->baseUrl . '/api/v1/tags', [
            'json' => ['IDs' => [$mail->sourceId], 'Tags' => [self::TAG]],
            'max_redirects' => 0,
        ])->getStatusCode();
    }

    public function name(): string
    {
        return self::NAME;
    }

    /** @return list<string> */
    public function queries(): array
    {
        return [
            sprintf('to:"%s" -tag:%s', $this->platformAddress, self::TAG),
            sprintf('to:"bounces+" to:"@%s" -tag:%s', $this->bounceDomain, self::TAG),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function search(string $query, int $limit): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . '/api/v1/search', [
                'query' => ['query' => $query, 'limit' => $limit],
                'max_redirects' => 0,
            ]);
            if ($response->getStatusCode() === 404) {
                return [];
            }
            $data = $response->toArray();
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('inbound.mailpit.search_failed', ['status' => $e->getResponse()->getStatusCode()]);

            return [];
        }

        return array_values(array_filter($data['messages'] ?? [], 'is_array'));
    }

    private function raw(string $id): ?string
    {
        $response = $this->httpClient->request('GET', $this->baseUrl . '/api/v1/message/' . rawurlencode($id) . '/raw', ['max_redirects' => 0]);
        if ($response->getStatusCode() === 404) {
            return null;
        }

        return $response->getContent();
    }
}
