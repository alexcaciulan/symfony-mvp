<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\FilingChannel;
use PHPUnit\Framework\TestCase;

/** The one place the status label is chosen, for the case hero and the lists alike. */
final class LegalCaseDisplayStatusLabelTest extends TestCase
{
    public function testAnEmailTransmittedCaseWithoutADeclaredDateIsNeverShownAsFiled(): void
    {
        $case = (new LegalCase())->setStatus(CaseStatus::CERERE_DEPUSA);
        $case->setFilingChannel(FilingChannel::EMAIL);

        self::assertSame('filing.status.hero_label', $case->displayStatusLabel());
    }

    public function testOnceTheLawyerDeclaresTheDateItIsShownAsFiled(): void
    {
        $case = (new LegalCase())->setStatus(CaseStatus::CERERE_DEPUSA);
        $case->setFilingChannel(FilingChannel::EMAIL);
        $case->setFiledAt(new \DateTimeImmutable('2026-10-20'));

        self::assertSame(CaseStatus::CERERE_DEPUSA->label(), $case->displayStatusLabel());
    }

    public function testAnotherChannelOrStatusUsesTheStatusLabel(): void
    {
        $registry = (new LegalCase())->setStatus(CaseStatus::CERERE_DEPUSA);
        $registry->setFilingChannel(FilingChannel::REGISTRATURA);
        $generated = (new LegalCase())->setStatus(CaseStatus::CERERE_GENERATA);

        self::assertSame(CaseStatus::CERERE_DEPUSA->label(), $registry->displayStatusLabel());
        self::assertSame(CaseStatus::CERERE_GENERATA->label(), $generated->displayStatusLabel());
    }
}
