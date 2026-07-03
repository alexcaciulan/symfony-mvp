<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Service\Deadline\WorkingDayResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pas 4.1 — Tests for WorkingDayResolver.
 *
 * CPC art. 181 alin. 2 + Codul Muncii art. 139 + Orthodox Easter (Meeus
 * algorithm). 2026 e anul de referință pentru fixturi:
 *   - Vinerea Mare = 10 aprilie 2026 (vineri)
 *   - Paște ortodox = 12 aprilie 2026 (duminică) + 13 aprilie (luni)
 *   - Rusalii = 31 mai 2026 (duminică) + 1 iunie (luni, coincide cu Ziua Copilului)
 */
final class WorkingDayResolverTest extends TestCase
{
    public function testReturnsDateUnchangedWhenAlreadyWorkingDay(): void
    {
        $resolver = new WorkingDayResolver();
        $monday = new \DateTimeImmutable('2026-02-02'); // luni 2 februarie 2026

        $this->assertSame($monday->format('Y-m-d'), $resolver->nextWorkingDay($monday)->format('Y-m-d'));
    }

    public function testAddWorkingDaysBasic(): void
    {
        $resolver = new WorkingDayResolver();
        // Mon 2 Feb + 3 working days = Thu 5 Feb (no weekend in between).
        $this->assertSame('2026-02-05', $resolver->addWorkingDays(new \DateTimeImmutable('2026-02-02'), 3)->format('Y-m-d'));
    }

    public function testAddWorkingDaysSkipsWeekend(): void
    {
        $resolver = new WorkingDayResolver();
        // Thu 5 Feb + 2 working days = Mon 9 Feb (skips Saturday-Sunday).
        $this->assertSame('2026-02-09', $resolver->addWorkingDays(new \DateTimeImmutable('2026-02-05'), 2)->format('Y-m-d'));
    }

    public function testAddWorkingDaysZeroReturnsUnchanged(): void
    {
        $resolver = new WorkingDayResolver();
        $monday = new \DateTimeImmutable('2026-02-02');
        $this->assertSame('2026-02-02', $resolver->addWorkingDays($monday, 0)->format('Y-m-d'));
    }

    public function testAddWorkingDaysAbsorbsChristmasCluster(): void
    {
        $resolver = new WorkingDayResolver();
        // Wed 23 Dec + 5 working days = Thu 31 Dec: the 25-26 Dec holidays plus the
        // weekend stretch the 5 working days over 8 calendar days.
        $this->assertSame('2026-12-31', $resolver->addWorkingDays(new \DateTimeImmutable('2026-12-23'), 5)->format('Y-m-d'));
    }

    public function testSkipsSaturdayToMonday(): void
    {
        $resolver = new WorkingDayResolver();
        $saturday = new \DateTimeImmutable('2026-02-07'); // sâmbătă

        $this->assertSame('2026-02-09', $resolver->nextWorkingDay($saturday)->format('Y-m-d'));
    }

    public function testSkipsSundayToMonday(): void
    {
        $resolver = new WorkingDayResolver();
        $sunday = new \DateTimeImmutable('2026-02-08'); // duminică

        $this->assertSame('2026-02-09', $resolver->nextWorkingDay($sunday)->format('Y-m-d'));
    }

    public function testSkipsFixedHolidayCraciun(): void
    {
        $resolver = new WorkingDayResolver();
        $craciun = new \DateTimeImmutable('2026-12-25'); // vineri Crăciun

        // 25 vineri Crăciun + 26 sâmbătă Crăciun + 27 duminică weekend → luni 28
        $this->assertSame('2026-12-28', $resolver->nextWorkingDay($craciun)->format('Y-m-d'));
    }

    public function testSkipsLongHolidayChainAroundEaster(): void
    {
        $resolver = new WorkingDayResolver();
        $vinereaMare = new \DateTimeImmutable('2026-04-10'); // vineri Vinerea Mare

        // 10 vineri (Vinerea Mare) + 11 sâmbătă + 12 duminică (Paște) + 13 luni (Paște) → marți 14
        $this->assertSame('2026-04-14', $resolver->nextWorkingDay($vinereaMare)->format('Y-m-d'));
    }

    public function testSkipsLongChainAcrossYearEnd(): void
    {
        $resolver = new WorkingDayResolver();
        $newYear = new \DateTimeImmutable('2026-01-01'); // joi Anul Nou

        // 1 joi (Anul Nou) + 2 vineri (Anul Nou) + 3 sâmbătă + 4 duminică → luni 5
        $this->assertSame('2026-01-05', $resolver->nextWorkingDay($newYear)->format('Y-m-d'));
    }

    public function testSkipsOrthodoxEasterSunday2026(): void
    {
        $resolver = new WorkingDayResolver();
        $easter = new \DateTimeImmutable('2026-04-12'); // duminică Paște ortodox

        // 12 duminică + 13 luni (Paște luni) → marți 14
        $this->assertSame('2026-04-14', $resolver->nextWorkingDay($easter)->format('Y-m-d'));
    }

    public function testSkipsRusalii2026(): void
    {
        $resolver = new WorkingDayResolver();
        $rusalii = new \DateTimeImmutable('2026-05-31'); // duminică Rusalii

        // 31 mai duminică + 1 iunie luni (Rusalii + Ziua Copilului) → marți 2
        $this->assertSame('2026-06-02', $resolver->nextWorkingDay($rusalii)->format('Y-m-d'));
    }

    public function testHonorsAdditionalHolidaysFromConfig(): void
    {
        $resolver = new WorkingDayResolver(['2026-07-15']);
        $wednesday = new \DateTimeImmutable('2026-07-15');

        // miercuri 15 declarat sărbătoare → joi 16 zi lucrătoare
        $this->assertSame('2026-07-16', $resolver->nextWorkingDay($wednesday)->format('Y-m-d'));
    }

    public function testIgnoresAdditionalHolidaysOutsideTargetYear(): void
    {
        $resolver = new WorkingDayResolver(['2025-07-15']); // an diferit
        $wednesday = new \DateTimeImmutable('2026-07-15'); // 2026, miercuri

        // Miercuri 15 iulie 2026 e zi lucrătoare normală — config-ul vizează 2025.
        $this->assertSame('2026-07-15', $resolver->nextWorkingDay($wednesday)->format('Y-m-d'));
    }

    public function testIsHolidayDistinguishesWeekends(): void
    {
        $resolver = new WorkingDayResolver();
        $saturday = new \DateTimeImmutable('2026-02-07'); // sâmbătă oarecare

        // Sâmbătă NU e sărbătoare legală, e weekend. Ambele blochează working day.
        $this->assertFalse($resolver->isHoliday($saturday));
        $this->assertFalse($resolver->isWorkingDay($saturday));
    }

    public function testGetRomanianHolidaysForYearIncludesFixedAndComputed(): void
    {
        $resolver = new WorkingDayResolver();
        $holidays = $resolver->getRomanianHolidaysForYear(2026);
        $iso = array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $holidays);

        // Sărbători fixe (Codul Muncii art. 139)
        $this->assertContains('2026-01-01', $iso);
        $this->assertContains('2026-01-02', $iso);
        $this->assertContains('2026-01-24', $iso);
        $this->assertContains('2026-05-01', $iso);
        $this->assertContains('2026-06-01', $iso);
        $this->assertContains('2026-08-15', $iso);
        $this->assertContains('2026-11-30', $iso);
        $this->assertContains('2026-12-01', $iso);
        $this->assertContains('2026-12-25', $iso);
        $this->assertContains('2026-12-26', $iso);

        // Calculate ortodoxe
        $this->assertContains('2026-04-10', $iso); // Vinerea Mare
        $this->assertContains('2026-04-12', $iso); // Paște duminică
        $this->assertContains('2026-04-13', $iso); // Paște luni
        $this->assertContains('2026-05-31', $iso); // Rusalii duminică
        // 2026-06-01 e ambele Rusalii luni + Ziua Copilului — deja verificat ca prezent.
    }

    public function testComputesOrthodoxEaster2025Correctly(): void
    {
        // Paștele ortodox 2025 = 20 aprilie (verificare cross-year algoritm Meeus).
        $resolver = new WorkingDayResolver();
        $easter = new \DateTimeImmutable('2025-04-20');

        // Duminică 20 + luni 21 (Paște luni) → marți 22
        $this->assertSame('2025-04-22', $resolver->nextWorkingDay($easter)->format('Y-m-d'));
    }

    public function testThrowsForYearsBeyondGuardWindow(): void
    {
        // Algoritmul Meeus + offset +13 zile e calibrat pentru 1900-2099. La 2100
        // offsetul Iulian→Gregorian devine +14 (regula seculară). Validăm că
        // resolver-ul aruncă explicit în loc să returneze date silent gresite.
        $resolver = new WorkingDayResolver();
        $futureDate = new \DateTimeImmutable('2100-04-15');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/calibrated for years 1900-2099/');

        $resolver->getRomanianHolidaysForYear((int) $futureDate->format('Y'));
    }
}
