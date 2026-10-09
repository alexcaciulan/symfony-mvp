<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\PortalCaseMatchSource;
use App\Enum\PortalCaseMatchStatus;
use PHPUnit\Framework\TestCase;

/**
 * A court case found on the portal is a proposal until the lawyer decides, and
 * a number set aside stays set aside: the daily search must not bring it back.
 */
final class PortalCaseMatchTest extends TestCase
{
    public function testAFoundNumberIsProposedOnce(): void
    {
        $case = new LegalCase();

        self::assertTrue($case->proposePortalMatch('100/211/2026', PortalCaseMatchSource::AUTO));
        self::assertFalse($case->proposePortalMatch('100/211/2026', PortalCaseMatchSource::MANUAL), 'already proposed');
        self::assertSame('100/211/2026', $case->getPortalProposedNumber());
        self::assertCount(1, $case->getPortalCaseMatches());
    }

    public function testANumberSetAsideIsNeverProposedAgain(): void
    {
        $case = new LegalCase();
        $lawyer = new User();
        $case->proposePortalMatch('100/211/2026', PortalCaseMatchSource::AUTO);

        self::assertSame('100/211/2026', $case->dismissPortalProposal($lawyer));
        self::assertFalse($case->proposePortalMatch('100/211/2026', PortalCaseMatchSource::MANUAL));

        self::assertNull($case->getPortalProposedNumber());
        self::assertSame(['100/211/2026'], $case->getDismissedPortalNumbers());
        $match = $case->getPortalCaseMatches()->first();
        self::assertSame(PortalCaseMatchStatus::DISMISSED, $match->getStatus());
        self::assertSame($lawyer, $match->getDecidedBy());
        self::assertNotNull($match->getDecidedAt());
    }

    public function testAnotherNumberCanBeProposedAfterOneIsSetAside(): void
    {
        $case = new LegalCase();
        $case->proposePortalMatch('100/211/2026', PortalCaseMatchSource::AUTO);
        $case->dismissPortalProposal(null);
        $case->proposePortalMatch('200/211/2026', PortalCaseMatchSource::AUTO);
        $case->dismissPortalProposal(null);

        self::assertTrue($case->proposePortalMatch('300/211/2026', PortalCaseMatchSource::AUTO));
        self::assertSame(['100/211/2026', '200/211/2026'], $case->getDismissedPortalNumbers(), 'every number set aside is remembered');
        self::assertSame('300/211/2026', $case->getPortalProposedNumber());
    }

    public function testAProposalWaitingIsNotReplaced(): void
    {
        // The case page shows the waiting one, and its button confirms what is shown.
        $case = new LegalCase();
        $case->proposePortalMatch('100/211/2026', PortalCaseMatchSource::AUTO);

        self::assertFalse($case->proposePortalMatch('200/211/2026', PortalCaseMatchSource::MANUAL));

        self::assertSame('100/211/2026', $case->getPortalProposedNumber());
        self::assertCount(1, $case->getPortalCaseMatches());
    }

    public function testConfirmingTheProposedNumberAcceptsIt(): void
    {
        $case = new LegalCase();
        $lawyer = new User();
        $case->proposePortalMatch('100/211/2026', PortalCaseMatchSource::AUTO);

        $case->setCourtCaseNumber('100/211/2026', $lawyer);

        $match = $case->getPortalCaseMatches()->first();
        self::assertSame(PortalCaseMatchStatus::ACCEPTED, $match->getStatus());
        self::assertSame($lawyer, $match->getDecidedBy());
        self::assertNull($case->getPortalProposedNumber());
    }

    public function testConfirmingAnotherNumberByHandSetsTheProposalAside(): void
    {
        $case = new LegalCase();
        $case->proposePortalMatch('100/211/2026', PortalCaseMatchSource::AUTO);

        $case->setCourtCaseNumber('555/211/2026');

        self::assertSame(PortalCaseMatchStatus::DISMISSED, $case->getPortalCaseMatches()->first()->getStatus());
        self::assertNull($case->getPortalProposedNumber(), 'no proposal left hanging once the number is known');
    }

    public function testConfirmingANumberSetAsideBeforeAcceptsIt(): void
    {
        $case = new LegalCase();
        $case->proposePortalMatch('100/211/2026', PortalCaseMatchSource::AUTO);
        $case->dismissPortalProposal(null);

        $case->setCourtCaseNumber('100/211/2026');

        self::assertSame(PortalCaseMatchStatus::ACCEPTED, $case->getPortalCaseMatches()->first()->getStatus(), 'the lawyer changed their mind: the record says so');
        self::assertSame([], $case->getDismissedPortalNumbers());
    }

    public function testNothingIsProposedOnceTheNumberIsKnown(): void
    {
        $case = new LegalCase();
        $case->setCourtCaseNumber('555/211/2026');

        self::assertFalse($case->proposePortalMatch('100/211/2026', PortalCaseMatchSource::AUTO));
        self::assertCount(0, $case->getPortalCaseMatches());
    }
}
