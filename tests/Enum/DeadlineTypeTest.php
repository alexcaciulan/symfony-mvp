<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use PHPUnit\Framework\TestCase;

class DeadlineTypeTest extends TestCase
{
    public function testHasSevenCases(): void
    {
        $this->assertCount(7, DeadlineType::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (DeadlineType::cases() as $case) {
            $this->assertNotEmpty($case->label());
        }
    }

    public function testDefaultPriorityMatchesAnalizaFluxuriSection8(): void
    {
        // ANALIZA-FLUXURI section 8 table (linii 466-471):
        // PRESCRIPTIE + CERERE_IN_ANULARE => CRITICAL (impact juridic direct)
        // RASPUNS_SOMATIE + DEPUNERE_CERERE => HIGH (15 zile termen procedural)
        // JUDECATA + OTHER => MEDIUM
        $this->assertSame(DeadlinePriority::CRITICAL, DeadlineType::CERERE_IN_ANULARE->defaultPriority());
        $this->assertSame(DeadlinePriority::CRITICAL, DeadlineType::PRESCRIPTIE->defaultPriority());
        $this->assertSame(DeadlinePriority::CRITICAL, DeadlineType::PRESCRIPTIE_EXECUTARE->defaultPriority());
        $this->assertSame(DeadlinePriority::HIGH, DeadlineType::DEPUNERE_CERERE->defaultPriority());
        $this->assertSame(DeadlinePriority::HIGH, DeadlineType::RASPUNS_SOMATIE->defaultPriority());
        $this->assertSame(DeadlinePriority::MEDIUM, DeadlineType::JUDECATA->defaultPriority());
        $this->assertSame(DeadlinePriority::MEDIUM, DeadlineType::OTHER->defaultPriority());
    }
}
