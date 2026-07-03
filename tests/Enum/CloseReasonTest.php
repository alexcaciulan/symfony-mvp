<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CloseReason;
use PHPUnit\Framework\TestCase;

class CloseReasonTest extends TestCase
{
    public function testValuesPresent(): void
    {
        $this->assertSame(CloseReason::PAID, CloseReason::from('PAID'));
        $this->assertSame(CloseReason::PARTIAL, CloseReason::from('PARTIAL'));
        $this->assertSame(CloseReason::ABANDONED, CloseReason::from('ABANDONED'));
        $this->assertSame(CloseReason::INSOLVENT_EXECUTARE, CloseReason::from('INSOLVENT_EXECUTARE'));
    }

    public function testLegacyInsolventRemoved(): void
    {
        $this->assertNull(CloseReason::tryFrom('INSOLVENT'));
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (CloseReason::cases() as $case) {
            $this->assertSame('enum.close_reason.' . $case->value, $case->label());
        }
    }

    public function testTargetTransitionBifurcatesByReason(): void
    {
        // PAID/PARTIAL rank as successful (full or partial) recovery.
        $this->assertSame('inchide_succes', CloseReason::PAID->targetTransition());
        $this->assertSame('inchide_succes', CloseReason::PARTIAL->targetTransition());
        // ABANDONED + enforcement-phase insolvency close without recovery.
        $this->assertSame('inchide_fara_recuperare', CloseReason::ABANDONED->targetTransition());
        $this->assertSame('inchide_fara_recuperare', CloseReason::INSOLVENT_EXECUTARE->targetTransition());
    }
}
