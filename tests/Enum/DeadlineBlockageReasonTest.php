<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DeadlineBlockageReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeadlineBlockageReasonTest extends TestCase
{
    /** @return iterable<string, array{DeadlineBlockageReason, string, bool}> */
    public static function reasonProvider(): iterable
    {
        yield 'summons date' => [DeadlineBlockageReason::SUMMONS_COMMUNICATION_MISSING, 'date', true];
        yield 'summons proof' => [DeadlineBlockageReason::SUMMONS_PROOF_MISSING, 'piece', false];
        yield 'ruling date' => [DeadlineBlockageReason::RULING_COMMUNICATION_MISSING, 'date', true];
        yield 'stamp duty notice' => [DeadlineBlockageReason::STAMP_DUTY_NOTICE_MISSING, 'date', true];
        yield 'enforcement anchor' => [DeadlineBlockageReason::EXECUTION_ANCHOR_MISSING, 'date', true];
        yield 'annulment ruling date' => [DeadlineBlockageReason::ANNULMENT_RULING_COMMUNICATION_MISSING, 'date', true];
        yield 'bailiff number' => [DeadlineBlockageReason::ENFORCEMENT_REGISTRATION_NUMBER_MISSING, 'number', false];
    }

    #[DataProvider('reasonProvider')]
    public function testTheKindAndTheFatalMarkOfEachReason(DeadlineBlockageReason $reason, string $kind, bool $fatal): void
    {
        self::assertSame($kind, $reason->kind());
        self::assertSame($fatal, $reason->concernsFatalTerm());
    }

    /** A reason added to the enum without a line above would silently go untested. */
    public function testEveryReasonIsPinnedByTheTableAbove(): void
    {
        $covered = array_map(
            static fn (array $row): DeadlineBlockageReason => $row[0],
            iterator_to_array(self::reasonProvider()),
        );

        self::assertEqualsCanonicalizing(DeadlineBlockageReason::cases(), array_values($covered));
    }
}
