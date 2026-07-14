<?php

namespace App\Tests\Service\Court;

use App\Service\Court\LocalityNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Data-integrity guard on data/courts.json coverage (HG 1217/2023).
 * Runs without a database: it validates the generated coverage file so a bad
 * regeneration (missing localities, accidental overlaps) is caught in CI.
 */
class CourtCoverageDataTest extends TestCase
{
    /** @var array<int, array{name:string, county:string, type:string, coveredLocalities?:string[]}> */
    private array $courts;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../../data/courts.json';
        $this->courts = json_decode((string) file_get_contents($path), true);
    }

    public function testEveryUatIsCoveredByAtMostOneJudecatorie(): void
    {
        $seen = [];
        $overlaps = [];
        foreach ($this->courts as $court) {
            if ($court['type'] !== 'judecatorie') {
                continue;
            }
            $normCounty = LocalityNormalizer::normalize($court['county']);
            foreach ($court['coveredLocalities'] ?? [] as $loc) {
                $key = $normCounty . '|' . LocalityNormalizer::normalize($loc);
                if (isset($seen[$key])) {
                    $overlaps[] = $key . ': ' . $seen[$key] . ' + ' . $court['name'];
                }
                $seen[$key] = $court['name'];
            }
        }

        $this->assertSame([], $overlaps, 'A UAT must not belong to two judecatorii (CPC territorial jurisdiction is exclusive).');
    }

    public function testCoverageIsSubstantiallyPopulated(): void
    {
        $total = 0;
        foreach ($this->courts as $court) {
            if ($court['type'] === 'judecatorie') {
                $total += count($court['coveredLocalities'] ?? []);
            }
        }

        // ~3184 UAT links expected (3178 from the annex + 6 Bucharest sectors).
        $this->assertGreaterThan(3000, $total, 'Court coverage looks unpopulated; regeneration likely failed.');
    }

    #[DataProvider('trapLocalities')]
    public function testNonResidenceLocalityMapsToExpectedCourt(string $county, string $locality, string $expectedCourt): void
    {
        $normCounty = LocalityNormalizer::normalize($county);
        $normLoc = LocalityNormalizer::normalize($locality);

        $matched = null;
        foreach ($this->courts as $court) {
            if ($court['type'] !== 'judecatorie' || LocalityNormalizer::normalize($court['county']) !== $normCounty) {
                continue;
            }
            foreach ($court['coveredLocalities'] ?? [] as $loc) {
                if (LocalityNormalizer::normalize($loc) === $normLoc) {
                    $matched = $court['name'];
                    break 2;
                }
            }
        }

        $this->assertSame($expectedCourt, $matched, sprintf('%s (%s) must map to %s.', $locality, $county, $expectedCourt));
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function trapLocalities(): array
    {
        return [
            // Non-residence commune: the point of the whole feature.
            'Pangarati -> Piatra Neamt' => ['Neamț', 'Pângarați', 'Judecătoria Piatra Neamț'],
            // De-facto remap: suspended Judecatoria Baia de Arama -> Strehaia.
            'Ponoarele -> Strehaia' => ['Mehedinți', 'Ponoarele', 'Judecătoria Strehaia'],
            // Alias resolution: annex "Rișca" -> cities.json "Râșca".
            'Rasca -> Huedin' => ['Cluj', 'Râșca', 'Judecătoria Huedin'],
            // Suspended Judecatoria Insuratei: locality currently at Braila.
            'Insuratei -> Braila' => ['Brăila', 'Însurăței', 'Judecătoria Brăila'],
        ];
    }
}
