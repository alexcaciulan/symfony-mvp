<?php

namespace App\Tests\Command;

use App\Repository\CourtRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ImportCourtsCommandTest extends KernelTestCase
{
    private const SECTOR_1 = 'Judecătoria Sectorului 1 București';
    private const SECTOR_1_DRIFTED = 'Judecatoria Sectorului 1 Bucuresti';

    protected function tearDown(): void
    {
        // Shared test database: restore the name this class rewrites on purpose.
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'UPDATE court SET name = :real WHERE name = :drifted',
            ['real' => self::SECTOR_1, 'drifted' => self::SECTOR_1_DRIFTED],
        );

        parent::tearDown();
    }

    private function seedCities(Application $application): void
    {
        // Courts now reference county + city by FK, so the nomenclature must exist first.
        (new CommandTester($application->find('app:import-cities')))->execute([]);
    }

    public function testImportCreatesCourts(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $this->seedCities($application);

        $command = $application->find('app:import-courts');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Import finished', $output);

        $courtRepository = static::getContainer()->get(CourtRepository::class);
        $this->assertGreaterThan(0, $courtRepository->count([]));
    }

    public function testImportIsIdempotent(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $this->seedCities($application);

        $command = $application->find('app:import-courts');

        // Run first time
        $tester1 = new CommandTester($command);
        $tester1->execute([]);
        $tester1->assertCommandIsSuccessful();

        $courtRepository = static::getContainer()->get(CourtRepository::class);
        $countAfterFirst = $courtRepository->count([]);

        // Run second time
        $tester2 = new CommandTester($command);
        $tester2->execute([]);
        $tester2->assertCommandIsSuccessful();

        $countAfterSecond = $courtRepository->count([]);
        $this->assertSame($countAfterFirst, $countAfterSecond);

        $output = $tester2->getDisplay();
        $this->assertStringContainsString('skipped', $output);
    }

    public function testImportCreatesCorrectCourtTypes(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $this->seedCities($application);

        $command = $application->find('app:import-courts');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $courtRepository = static::getContainer()->get(CourtRepository::class);
        $counties = $courtRepository->findDistinctCounties();

        $this->assertGreaterThanOrEqual(42, count($counties));
        $this->assertContains('București', $counties);
        $this->assertContains('Cluj', $counties);

        $clujCourts = $courtRepository->findActiveByCounty('Cluj');
        $this->assertGreaterThanOrEqual(2, count($clujCourts));
    }

    public function testSpellingDriftDoesNotCreateADuplicateCourt(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $this->seedCities($application);

        (new CommandTester($application->find('app:import-courts')))->execute([]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $courtRepository = static::getContainer()->get(CourtRepository::class);

        $court = $courtRepository->findOneBy(['name' => self::SECTOR_1]);
        $this->assertNotNull($court);
        $courtId = $court->getId();
        $countBefore = $courtRepository->count([]);

        // A court stored without diacritics used to be treated as missing, so the
        // import created a second row and the original kept the portal code.
        $court->setName(self::SECTOR_1_DRIFTED);
        $em->flush();
        $em->clear();

        $tester = new CommandTester($application->find('app:import-courts'));
        $tester->execute(['--update' => true]);

        $this->assertSame($countBefore, $courtRepository->count([]), 'Spelling drift must not duplicate a court.');

        $reloaded = $courtRepository->find($courtId);
        $this->assertNotNull($reloaded);
        $this->assertSame(self::SECTOR_1, $reloaded->getName(), 'Update mode adopts the spelling from the data file.');

        // A court renamed by this very import is not missing from the data file.
        $this->assertStringNotContainsString(
            sprintf('Court "%s" exists in the database but not in courts.json', self::SECTOR_1),
            $tester->getDisplay(),
        );
    }
}
