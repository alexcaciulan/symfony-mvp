<?php

namespace App\Tests\Command;

use App\Entity\City;
use App\Entity\County;
use App\Enum\UatType;
use App\Repository\CityRepository;
use App\Repository\CountyRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ImportCitiesCommandTest extends KernelTestCase
{
    public function testImportCreatesCountiesAndCities(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('app:import-cities');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Import finished', $tester->getDisplay());

        $countyRepository = static::getContainer()->get(CountyRepository::class);
        $cityRepository = static::getContainer()->get(CityRepository::class);

        $this->assertGreaterThanOrEqual(42, $countyRepository->count([]));
        $this->assertGreaterThan(3000, $cityRepository->count([]));

        $cluj = $countyRepository->findOneByNormalizedName('cluj');
        $this->assertNotNull($cluj);

        $clujNapoca = $cityRepository->findOneByCountyAndNormalizedName($cluj, 'cluj-napoca');
        $this->assertNotNull($clujNapoca);
        $this->assertSame(UatType::MUNICIPIU, $clujNapoca->getType());
    }

    public function testImportCreatesBucharestSectors(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $tester = new CommandTester($application->find('app:import-cities'));
        $tester->execute([]);

        $countyRepository = static::getContainer()->get(CountyRepository::class);
        $cityRepository = static::getContainer()->get(CityRepository::class);

        $bucuresti = $countyRepository->findOneByNormalizedName('bucuresti');
        $this->assertNotNull($bucuresti);

        $sector3 = $cityRepository->findOneByCountyAndNormalizedName($bucuresti, 'sector 3');
        $this->assertNotNull($sector3);
        $this->assertSame(UatType::SECTOR, $sector3->getType());
    }

    public function testImportIsIdempotent(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('app:import-cities');

        $first = new CommandTester($command);
        $first->execute([]);
        $first->assertCommandIsSuccessful();

        $cityRepository = static::getContainer()->get(CityRepository::class);
        $countAfterFirst = $cityRepository->count([]);

        $second = new CommandTester($command);
        $second->execute([]);
        $second->assertCommandIsSuccessful();

        $this->assertSame($countAfterFirst, $cityRepository->count([]));
        $this->assertStringContainsString('skipped', $second->getDisplay());
    }
}
