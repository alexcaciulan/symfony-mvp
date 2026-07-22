<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Service\Document\UploadRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Real sliding-window limiters are built here on purpose: the test environment
 * runs every configured limiter on `no_limit`, so token arithmetic asserted
 * through the HTTP layer would assert nothing.
 */
final class UploadRateLimiterTest extends TestCase
{
    private const EXTRACTION_LIMIT = 20;
    private const REQUEST_LIMIT = 60;

    private RateLimiterFactory $extraction;
    private RateLimiterFactory $requests;
    private UploadRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->extraction = new RateLimiterFactory(
            ['id' => 'document_upload', 'policy' => 'sliding_window', 'limit' => self::EXTRACTION_LIMIT, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $this->requests = new RateLimiterFactory(
            ['id' => 'document_upload_request', 'policy' => 'sliding_window', 'limit' => self::REQUEST_LIMIT, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $this->limiter = new UploadRateLimiter($this->extraction, $this->requests);
    }

    public function testABatchOfOnlyKnownFilesCostsNoExtractionBudget(): void
    {
        self::assertTrue($this->limiter->acceptsBatch('lawyer', 3));
        self::assertTrue($this->limiter->acceptsExtractions('lawyer', 0));

        self::assertSame(self::EXTRACTION_LIMIT, $this->remainingExtraction('lawyer'));
    }

    public function testKnownFilesStillCostTrafficBudget(): void
    {
        $this->limiter->acceptsBatch('lawyer', 3);

        self::assertSame(self::REQUEST_LIMIT - 3, $this->remainingRequests('lawyer'));
    }

    public function testMixedBatchChargesOnlyTheStoredFilesToTheExtractionBudget(): void
    {
        $this->limiter->acceptsBatch('lawyer', 3);
        $this->limiter->acceptsExtractions('lawyer', 2);

        self::assertSame(self::EXTRACTION_LIMIT - 2, $this->remainingExtraction('lawyer'));
        self::assertSame(self::REQUEST_LIMIT - 3, $this->remainingRequests('lawyer'));
    }

    public function testFreshBatchChargesEveryFileToBothBudgets(): void
    {
        $this->limiter->acceptsBatch('lawyer', 4);
        $this->limiter->acceptsExtractions('lawyer', 4);

        self::assertSame(self::EXTRACTION_LIMIT - 4, $this->remainingExtraction('lawyer'));
        self::assertSame(self::REQUEST_LIMIT - 4, $this->remainingRequests('lawyer'));
    }

    public function testAnEmptyBatchStillCostsOneTrafficToken(): void
    {
        $this->limiter->acceptsBatch('lawyer', 0);

        self::assertSame(self::REQUEST_LIMIT - 1, $this->remainingRequests('lawyer'));
    }

    public function testReplayingKnownFilesIsBoundedByTheTrafficCeiling(): void
    {
        // Six full ten-file batches of already known documents: free on the
        // extraction budget, but the traffic ceiling closes afterwards.
        for ($i = 0; $i < 6; ++$i) {
            self::assertTrue($this->limiter->acceptsBatch('lawyer', 10));
            self::assertTrue($this->limiter->acceptsExtractions('lawyer', 0));
        }

        self::assertFalse($this->limiter->acceptsBatch('lawyer', 10));
        self::assertSame(self::EXTRACTION_LIMIT, $this->remainingExtraction('lawyer'));
    }

    public function testExtractionBudgetRejectsBeyondTheLimit(): void
    {
        self::assertTrue($this->limiter->acceptsExtractions('lawyer', self::EXTRACTION_LIMIT));
        self::assertFalse($this->limiter->acceptsExtractions('lawyer', 1));
    }

    public function testBudgetsAreKeptPerUser(): void
    {
        $this->limiter->acceptsBatch('first', 5);
        $this->limiter->acceptsExtractions('first', 5);

        self::assertSame(self::EXTRACTION_LIMIT, $this->remainingExtraction('second'));
        self::assertSame(self::REQUEST_LIMIT, $this->remainingRequests('second'));
    }

    private function remainingExtraction(string $identifier): int
    {
        return $this->extraction->create($identifier)->consume(0)->getRemainingTokens();
    }

    private function remainingRequests(string $identifier): int
    {
        return $this->requests->create($identifier)->consume(0)->getRemainingTokens();
    }
}
