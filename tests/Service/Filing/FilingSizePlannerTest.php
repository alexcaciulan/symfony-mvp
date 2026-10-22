<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Service\Filing\FilingSizePlanner;
use PHPUnit\Framework\TestCase;

final class FilingSizePlannerTest extends TestCase
{
    private const BUDGET = 1_000_000;

    private FilingSizePlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new FilingSizePlanner();
    }

    public function testBudgetIsTheEncodedLimitInRawBytesMinusTheSignatureReserve(): void
    {
        self::assertSame(6_634_306, FilingSizePlanner::budget(9_500_000, 300_000));
    }

    public function testEverythingFitsInOneEmail(): void
    {
        $plan = $this->planner->plan(200_000, [$this->annex(1, 100_000, 2), $this->annex(2, 50_000, 1)], self::BUDGET, false);

        self::assertNull($plan->error);
        self::assertFalse($plan->needsCompression);
        self::assertSame([[1, 2]], $plan->volumes);
        self::assertSame(1, $plan->emailCount());
        self::assertSame(1, $plan->volumesInFirstEmail);
    }

    public function testATotalExactlyAtTheBudgetFitsAndOneByteOverAsksForCompression(): void
    {
        // 200 000 + overhead 60 000 + 2 pages x 2 000 + annex bytes = budget.
        $exact = self::BUDGET - 200_000 - FilingSizePlanner::DEFAULT_VOLUME_OVERHEAD - 2 * FilingSizePlanner::DEFAULT_PAGE_OVERHEAD;

        $fits = $this->planner->plan(200_000, [$this->annex(1, $exact, 2)], self::BUDGET, false);
        self::assertFalse($fits->needsCompression);
        self::assertSame(1, $fits->emailCount());
        self::assertSame([self::BUDGET], $fits->emailBytes);

        $over = $this->planner->plan(200_000, [$this->annex(1, $exact + 1, 2)], self::BUDGET, false);
        self::assertTrue($over->needsCompression);
        self::assertSame([], $over->volumes);
        self::assertNull($over->error);
    }

    public function testStillOverAfterCompressionSplitsAtDocumentBoundaries(): void
    {
        $annexes = [
            $this->annex(1, 300_000, 1),
            $this->annex(2, 500_000, 1),
            $this->annex(3, 600_000, 1),
            $this->annex(4, 200_000, 1),
        ];

        $plan = $this->planner->plan(400_000, $annexes, self::BUDGET, true);

        self::assertNull($plan->error);
        // Email 1: petition + POA (400 000) + volume 1 with annex 1 (362 000).
        self::assertSame([[1], [2], [3, 4]], $plan->volumes);
        self::assertSame(1, $plan->volumesInFirstEmail);
        self::assertSame(3, $plan->emailCount());
        self::assertSame(1, $plan->emailForVolume(1));
        self::assertSame(2, $plan->emailForVolume(2));
        self::assertSame(3, $plan->emailForVolume(3));
        foreach ($plan->emailBytes as $bytes) {
            self::assertLessThanOrEqual(self::BUDGET, $bytes);
        }
    }

    public function testFirstEmailCarriesOnlyThePetitionWhenTheFirstAnnexDoesNotFitBesideIt(): void
    {
        $plan = $this->planner->plan(800_000, [$this->annex(1, 500_000, 1), $this->annex(2, 300_000, 1)], self::BUDGET, true);

        self::assertSame([[1, 2]], $plan->volumes);
        self::assertSame(0, $plan->volumesInFirstEmail);
        self::assertSame([800_000, 60_000 + 502_000 + 302_000], $plan->emailBytes);
        self::assertSame(2, $plan->emailForVolume(1));
    }

    public function testASingleAnnexOverTheBudgetIsAnErrorNamingIt(): void
    {
        $plan = $this->planner->plan(100_000, [$this->annex(1, 10_000, 1), $this->annex(7, 2_000_000, 3)], self::BUDGET, true);

        self::assertSame(FilingSizePlanner::ERROR_ANNEX_TOO_LARGE, $plan->error);
        self::assertSame(7, $plan->errorDocumentId);
    }

    public function testFirstEmailAloneOverTheBudgetIsAnError(): void
    {
        $plan = $this->planner->plan(self::BUDGET + 1, [], self::BUDGET, false);

        self::assertSame(FilingSizePlanner::ERROR_FIRST_EMAIL_TOO_LARGE, $plan->error);
    }

    /** @return array{id: int, bytes: int, pages: int} */
    private function annex(int $id, int $bytes, int $pages): array
    {
        return ['id' => $id, 'bytes' => $bytes, 'pages' => $pages];
    }
}
