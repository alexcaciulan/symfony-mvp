<?php

declare(strict_types=1);

namespace App\Tests\Service\StampDuty;

use App\Entity\LegalCase;
use App\Enum\PortalCaseMatchSource;
use App\Service\StampDuty\StampDutyPaymentWindow;
use PHPUnit\Framework\TestCase;

/**
 * The five days of practice the lawyer has to pay the duty once the court file number
 * is known. Not a legal term: the court's notice to stamp gives at most ten days and
 * may reach the client rather than the office, so the window is what the reminders
 * are paced on instead.
 */
final class StampDutyPaymentWindowTest extends TestCase
{
    private StampDutyPaymentWindow $window;

    protected function setUp(): void
    {
        $this->window = new StampDutyPaymentWindow();
    }

    public function testTheWindowEndsFiveDaysAfterTheNumberBecameKnown(): void
    {
        $case = new LegalCase();
        $case->setCourtCaseNumber('100/211/2026');
        $case->setCourtCaseNumberKnownAt(new \DateTimeImmutable('2026-08-01 16:45'));

        self::assertSame('2026-08-06', $this->window->payBy($case)?->format('Y-m-d'));
    }

    public function testTheLastDayOfTheWindowIsStillInsideIt(): void
    {
        $case = new LegalCase();
        $case->setCourtCaseNumber('100/211/2026');
        $case->setCourtCaseNumberKnownAt(new \DateTimeImmutable('2026-08-01 16:45'));

        self::assertTrue($this->window->isOpenOn($case, new \DateTimeImmutable('2026-08-06 23:00')));
        self::assertFalse($this->window->isOpenOn($case, new \DateTimeImmutable('2026-08-07 00:30')));
    }

    /** A proposal the lawyer has not answered starts the clock: the court does not wait for the answer. */
    public function testAProposedNumberStartsTheWindowFromTheProposal(): void
    {
        $case = new LegalCase();
        self::assertTrue($case->proposePortalMatch('101/211/2026', PortalCaseMatchSource::AUTO));

        $payBy = $this->window->payBy($case);
        self::assertNotNull($payBy);
        self::assertSame(
            (new \DateTimeImmutable('today'))->modify('+5 days')->format('Y-m-d'),
            $payBy->format('Y-m-d'),
        );
    }

    /** Without any number the clock has not started, and a run is never "inside" it. */
    public function testNoNumberMeansNoWindow(): void
    {
        $case = new LegalCase();

        self::assertNull($this->window->payBy($case));
        self::assertFalse($this->window->isOpenOn($case, new \DateTimeImmutable()));
    }

    /** Older cases that never recorded the moment keep no window rather than an invented one. */
    public function testANumberWithoutItsDateHasNoWindow(): void
    {
        $case = new LegalCase();
        $case->setCourtCaseNumber('102/211/2026');
        $case->setCourtCaseNumberKnownAt(null);

        self::assertNull($this->window->payBy($case));
    }
}
