<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Company\AnafLookupException;
use App\Service\Company\AnafLookupService;

/**
 * ANAF lookup used in the test environment: answers from what a test put in,
 * and as an unavailable register otherwise, never over the network.
 *
 * The wizard's confirmation step looks the debtor's fiscal status up on its own,
 * so any test that walks to step 4 with an unsynced company debtor would reach
 * webservicesp.anaf.ro for real. Tests that need an answer call respondWith().
 *
 * Replaces {@see AnafLookupService} in config/services_test.yaml.
 */
final class OfflineAnafLookupService extends AnafLookupService
{
    /** @var array<string, array<string, mixed>> */
    private array $responses = [];

    /** @var list<string> */
    private array $lookups = [];

    public function __construct()
    {
    }

    /** @param array<string, mixed> $payload what {@see AnafLookupService::lookupByCui()} returns */
    public function respondWith(string $cui, array $payload): void
    {
        $this->responses[preg_replace('/\D/', '', $cui) ?? $cui] = $payload;
    }

    /** @return list<string> the CUIs looked up, in order */
    public function lookups(): array
    {
        return $this->lookups;
    }

    public function lookupByCui(string $cui): array
    {
        $digits = preg_replace('/\D/', '', $cui) ?? $cui;
        $this->lookups[] = $digits;

        if (!isset($this->responses[$digits])) {
            throw new AnafLookupException('exception.anaf.unavailable');
        }

        return $this->responses[$digits];
    }
}
