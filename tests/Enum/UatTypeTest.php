<?php

namespace App\Tests\Enum;

use App\Enum\UatType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UatTypeTest extends TestCase
{
    public function testHasFourCases(): void
    {
        $this->assertCount(4, UatType::cases());
    }

    public function testLabels(): void
    {
        $this->assertSame('enum.uat_type.municipiu', UatType::MUNICIPIU->label());
        $this->assertSame('enum.uat_type.oras', UatType::ORAS->label());
        $this->assertSame('enum.uat_type.comuna', UatType::COMUNA->label());
        $this->assertSame('enum.uat_type.sector', UatType::SECTOR->label());
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(UatType::MUNICIPIU, UatType::from('municipiu'));
        $this->assertSame(UatType::ORAS, UatType::from('oras'));
        $this->assertSame(UatType::COMUNA, UatType::from('comuna'));
        $this->assertSame(UatType::SECTOR, UatType::from('sector'));
    }

    /** @return iterable<string, array{?string, ?int}> */
    public static function sectorNames(): iterable
    {
        yield 'sector' => ['Sector 3', 3];
        yield 'last sector' => ['Sector 6', 6];
        yield 'not a sector number' => ['Sector 7', null];
        yield 'town' => ['Cluj-Napoca', null];
        yield 'prefix only' => ['Sector 3 Nord', null];
        yield 'trailing newline' => ["Sector 3\n", null];
        yield 'missing' => [null, null];
    }

    #[DataProvider('sectorNames')]
    public function testBucharestSectorNumberIsReadOnlyFromASectorName(?string $name, ?int $expected): void
    {
        $this->assertSame($expected, UatType::bucharestSectorNumber($name));
    }
}
