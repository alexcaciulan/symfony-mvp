<?php

namespace App\Tests\Command;

use App\Repository\CourtRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ImportCourtsCommandTest extends KernelTestCase
{
    public function testImportCreatesCourts(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

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
}
