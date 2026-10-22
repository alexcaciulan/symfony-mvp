<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Service\Filing\FilingPackageFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilingPackageFingerprintTest extends TestCase
{
    public function testSameInputsGiveTheSameHash(): void
    {
        self::assertSame(FilingPackageFingerprint::hash(self::manifest()), FilingPackageFingerprint::hash(self::manifest()));
        self::assertNull(FilingPackageFingerprint::diff(self::manifest(), self::manifest()));
    }

    public function testAnnexInputOrderDoesNotChangeTheHash(): void
    {
        $reordered = self::manifest();
        $reordered['annexes'] = array_reverse($reordered['annexes']);

        self::assertSame(FilingPackageFingerprint::hash(self::manifest()), FilingPackageFingerprint::hash($reordered));
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    #[DataProvider('changes')]
    public function testEachComponentChangesTheHashAndIsNamedByTheDiff(callable $change, string $reason): void
    {
        $changed = $change(self::manifest());

        self::assertNotSame(FilingPackageFingerprint::hash(self::manifest()), FilingPackageFingerprint::hash($changed));
        self::assertSame($reason, FilingPackageFingerprint::diff(self::manifest(), $changed));
    }

    /** @return iterable<string, array{0: callable, 1: string}> */
    public static function changes(): iterable
    {
        yield 'petition bytes' => [static fn (array $m): array => ['petitionSha256' => str_repeat('f', 64)] + $m, 'petition'];
        yield 'annex sha' => [static function (array $m): array { $m['annexes'][1]['sha256'] = 'changed'; return $m; }, 'annexes'];
        yield 'annex added' => [static function (array $m): array { $m['annexes'][] = ['id' => 9, 'type' => 'factura', 'sha256' => 'x']; return $m; }, 'annexes'];
        yield 'poa date' => [static function (array $m): array { $m['powerOfAttorney']['date'] = '2026-03-02'; return $m; }, 'power_of_attorney'];
        yield 'poa series' => [static function (array $m): array { $m['powerOfAttorney']['series'] = 'B'; return $m; }, 'power_of_attorney'];
        yield 'poa number' => [static function (array $m): array { $m['powerOfAttorney']['number'] = '99'; return $m; }, 'power_of_attorney'];
        yield 'claim total' => [static function (array $m): array { $m['claim']['amount'] = '5001.00'; return $m; }, 'claim_totals'];
        yield 'reference date' => [static fn (array $m): array => ['accessoryReferenceDate' => '2026-02-02'] + $m, 'interest_date'];
        yield 'court' => [static fn (array $m): array => ['courtId' => 8] + $m, 'court'];
        yield 'opis placement' => [static fn (array $m): array => ['opisPlacement' => 'SEPARATE'] + $m, 'opis_placement'];
        yield 'stamp duty status' => [static function (array $m): array { $m['stampDuty']['status'] = 'AMANATA_REGULARIZARE'; return $m; }, 'stamp_duty'];
        yield 'stamp proof' => [static function (array $m): array { $m['stampDuty']['proofAnnexed'] = false; return $m; }, 'stamp_duty'];
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        return [
            'petitionSha256' => str_repeat('a', 64),
            'annexes' => [
                ['id' => 3, 'type' => 'somatie', 'sha256' => 's1'],
                ['id' => 5, 'type' => 'factura', 'sha256' => 's2'],
            ],
            'powerOfAttorney' => ['id' => 4, 'sha256' => 'p', 'series' => 'A', 'number' => '12', 'date' => '2026-03-01'],
            'claim' => ['amount' => '5000.00', 'calculatedInterest' => '120.50', 'currency' => 'RON', 'items' => [[1, '5000.00']]],
            'accessoryReferenceDate' => '2026-02-01',
            'courtId' => 7,
            'opisPlacement' => 'MERGED',
            'stampDuty' => ['status' => 'ACHITATA', 'proofAnnexed' => true],
        ];
    }
}
