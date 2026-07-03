<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * Calculator zile lucrătoare conform CPC art. 181 alin. (2) + Codul Muncii art. 139.
 *
 * Sărbători legale RO acoperite:
 *  - Fixe: 1-2 ianuarie, 24 ianuarie, 1 mai, 1 iunie, 15 august, 30 noiembrie,
 *    1 decembrie, 25-26 decembrie
 *  - Calculate (calendar ortodox): Vinerea Mare (Paște - 2), Paștele (duminică +
 *    luni), Rusaliile (Paște + 49 + 50 zile)
 *  - Suplimentare via config (`lexrecovery.working_days.additional_holidays`) —
 *    pentru OG-uri ad-hoc (ex: vacanță judecătorească)
 *
 * Algoritm Paște ortodox: formula Meeus (calendar Iulian) + 13 zile pentru
 * convertire la calendar Gregorian. Valid pentru anii 1900-2099.
 */
final class WorkingDayResolver
{
    /** @var array<int, array<string, true>> Cache: an → set de date YYYY-MM-DD sărbători */
    private array $holidaysByYear = [];

    /** @var array<string, true> Set de date YYYY-MM-DD din config */
    private readonly array $additionalHolidaysSet;

    /**
     * @param list<string> $additionalHolidays Date ISO-8601 (YYYY-MM-DD) declarate
     *                                          ca sărbători suplimentare prin config.
     */
    public function __construct(array $additionalHolidays = [])
    {
        $set = [];
        foreach ($additionalHolidays as $date) {
            $set[$date] = true;
        }
        $this->additionalHolidaysSet = $set;
    }

    /**
     * Returnează prima zi lucrătoare ≥ $candidate. Dacă $candidate e deja zi
     * lucrătoare (luni-vineri, nu sărbătoare), îl returnează nemodificat.
     * Altfel incrementează cu 1 zi recursiv (lanțuri lungi de sărbători
     * sunt acoperite — ex: 24 dec joi → 25-26 sărbători + 27-28 weekend → 29 luni).
     */
    public function nextWorkingDay(\DateTimeImmutable $candidate): \DateTimeImmutable
    {
        $current = $candidate;
        while (!$this->isWorkingDay($current)) {
            $current = $current->modify('+1 day');
        }

        return $current;
    }

    /** Advances $from by $n working days (each weekend/holiday is skipped). Returns $from unchanged when $n <= 0. */
    public function addWorkingDays(\DateTimeImmutable $from, int $n): \DateTimeImmutable
    {
        $current = $from;
        for ($added = 0; $added < $n; ++$added) {
            $current = $this->nextWorkingDay($current->modify('+1 day'));
        }

        return $current;
    }

    public function isWorkingDay(\DateTimeImmutable $date): bool
    {
        $dayOfWeek = (int) $date->format('N'); // 1=Monday, 7=Sunday
        if ($dayOfWeek >= 6) {
            return false;
        }

        return !$this->isHoliday($date);
    }

    /**
     * Sărbătoare legală RO conform Codul Muncii art. 139 + config override.
     * NU include weekend-urile (folosește `isWorkingDay()` pentru combinație).
     */
    public function isHoliday(\DateTimeImmutable $date): bool
    {
        $dateKey = $date->format('Y-m-d');
        $year = (int) $date->format('Y');

        return isset($this->getHolidaysForYear($year)[$dateKey]);
    }

    /**
     * @return list<\DateTimeImmutable> Sărbătorile legale RO într-un an
     *                                   (fixe + ortodoxe + suplimentare). Ordine
     *                                   cronologică.
     */
    public function getRomanianHolidaysForYear(int $year): array
    {
        $set = $this->getHolidaysForYear($year);
        $dates = array_keys($set);
        sort($dates);

        return array_map(
            static fn (string $iso): \DateTimeImmutable => new \DateTimeImmutable($iso),
            $dates,
        );
    }

    /**
     * @return array<string, true> Set indexat după YYYY-MM-DD pentru lookup O(1).
     */
    private function getHolidaysForYear(int $year): array
    {
        if (isset($this->holidaysByYear[$year])) {
            return $this->holidaysByYear[$year];
        }

        $set = [];

        // Sărbători fixe (Codul Muncii art. 139)
        foreach (self::FIXED_HOLIDAYS as [$month, $day]) {
            $iso = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $set[$iso] = true;
        }

        // Sărbători ortodoxe calculate
        $easterSunday = $this->computeOrthodoxEaster($year);
        $set[$easterSunday->modify('-2 days')->format('Y-m-d')] = true; // Vinerea Mare
        $set[$easterSunday->format('Y-m-d')] = true;                    // Paște duminică
        $set[$easterSunday->modify('+1 day')->format('Y-m-d')] = true;  // Paște luni
        $set[$easterSunday->modify('+49 days')->format('Y-m-d')] = true; // Rusalii duminică
        $set[$easterSunday->modify('+50 days')->format('Y-m-d')] = true; // Rusalii luni

        // Suplimentare din config — DOAR dacă cad în anul cerut
        foreach (array_keys($this->additionalHolidaysSet) as $iso) {
            if (str_starts_with($iso, sprintf('%04d-', $year))) {
                $set[$iso] = true;
            }
        }

        $this->holidaysByYear[$year] = $set;

        return $set;
    }

    /**
     * Algoritm Meeus pentru Paștele ortodox (calendar Iulian) + 13 zile pentru
     * convertire Gregorian. Verificat pentru 2025 = 20 aprilie, 2026 = 12 aprilie.
     *
     * Offsetul Iulian→Gregorian este +13 zile pentru anii 1900-2099; la 1 martie
     * 2100 offsetul crește la +14 zile (regula seculară Gregorian). Pentru anii
     * în afara acestui interval, algoritmul ar produce date eronate silently
     * → DomainException explicit ca să fie evident la debug.
     */
    private function computeOrthodoxEaster(int $year): \DateTimeImmutable
    {
        if ($year < 1900 || $year > 2099) {
            throw new \DomainException(sprintf(
                'Orthodox Easter algorithm (Meeus + 13-day Julian offset) is calibrated for years 1900-2099; %d is outside this range.',
                $year,
            ));
        }

        $a = $year % 4;
        $b = $year % 7;
        $c = $year % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $month = (int) floor(($d + $e + 114) / 31);
        $day = (($d + $e + 114) % 31) + 1;

        $julianEaster = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

        return $julianEaster->modify('+13 days');
    }

    /** Codul Muncii art. 139. Format: [month, day]. */
    private const FIXED_HOLIDAYS = [
        [1, 1],   // Anul Nou
        [1, 2],   // Anul Nou
        [1, 24],  // Unirea Principatelor Române
        [5, 1],   // Ziua Muncii
        [6, 1],   // Ziua Copilului
        [8, 15],  // Adormirea Maicii Domnului
        [11, 30], // Sf. Andrei
        [12, 1],  // Ziua Națională
        [12, 25], // Crăciunul
        [12, 26], // Crăciunul
    ];
}
