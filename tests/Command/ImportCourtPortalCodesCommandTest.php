<?php

namespace App\Tests\Command;

use App\Repository\CourtRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ImportCourtPortalCodesCommandTest extends KernelTestCase
{
    private const SECTOR_1 = 'Judecătoria Sectorului 1 București';
    private const SECTOR_1_CODE = 'JudecatoriaSECTORUL1BUCURESTI';
    private const SECTOR_1_DRIFTED = 'Judecatoria Sectorului 1 Bucuresti';
    private const SECTOR_1_UNKNOWN = 'Instanță Fără Corespondent În Fișier';

    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->application = new Application(self::$kernel);

        // Courts reference county + city by FK, so the nomenclature must exist first.
        (new CommandTester($this->application->find('app:import-cities')))->execute([]);
        (new CommandTester($this->application->find('app:import-courts')))->execute([]);
    }

    protected function tearDown(): void
    {
        // The test database is shared, and some cases rewrite court names on
        // purpose. Put the nomenclature back before the next test imports it.
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ([self::SECTOR_1_DRIFTED, self::SECTOR_1_UNKNOWN] as $alias) {
            $connection->executeStatement(
                'UPDATE court SET name = :real WHERE name = :alias',
                ['real' => self::SECTOR_1, 'alias' => $alias],
            );
        }

        parent::tearDown();
    }

    private function runImport(array $input = []): CommandTester
    {
        $tester = new CommandTester($this->application->find('app:import-court-portal-codes'));
        $tester->execute($input);

        return $tester;
    }

    public function testImportAssignsPortalCodesToCourts(): void
    {
        $tester = $this->runImport();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Import finished', $tester->getDisplay());

        $courtRepository = static::getContainer()->get(CourtRepository::class);
        $court = $courtRepository->findOneBy(['name' => self::SECTOR_1]);

        $this->assertNotNull($court);
        $this->assertSame(self::SECTOR_1_CODE, $court->getPortalCode());
    }

    public function testEveryPortalCodeIsUniqueAcrossCourts(): void
    {
        $this->runImport();

        $codes = [];
        foreach (static::getContainer()->get(CourtRepository::class)->findAll() as $court) {
            $code = $court->getPortalCode();
            if ($code !== null && $code !== '') {
                $codes[] = $code;
            }
        }

        $this->assertSame(array_unique($codes), $codes, 'A portal code identifies one institution and cannot be shared.');
    }

    public function testSecondRunReportsCodesAsAlreadyCurrent(): void
    {
        $this->runImport();
        $tester = $this->runImport();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('0 court(s) updated', $tester->getDisplay());
    }

    public function testRenamedCourtIsStillMatchedThroughNormalization(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $courtRepository = static::getContainer()->get(CourtRepository::class);

        // Simulate spelling drift between the data file and the database.
        $court = $courtRepository->findOneBy(['name' => self::SECTOR_1]);
        $this->assertNotNull($court);
        $court->setName(self::SECTOR_1_DRIFTED);
        $em->flush();

        $tester = $this->runImport();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $em->refresh($court);
        $this->assertSame(self::SECTOR_1_CODE, $court->getPortalCode());
    }

    public function testCommandFailsWhenACourtIsMissing(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $courtRepository = static::getContainer()->get(CourtRepository::class);

        $court = $courtRepository->findOneBy(['name' => self::SECTOR_1]);
        $this->assertNotNull($court);
        $court->setName(self::SECTOR_1_UNKNOWN);
        $em->flush();

        $tester = $this->runImport();

        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'An unmatched court must not pass silently.');
        $this->assertStringContainsString('No court matches', $tester->getDisplay());
    }

    public function testConflictingCodeIsRefusedWithoutWriting(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $courtRepository = static::getContainer()->get(CourtRepository::class);

        // Bocșa has no code in the data file, so it never releases the one it holds:
        // a genuine conflict, unlike a code merely moving between two listed courts.
        $holder = $courtRepository->findOneBy(['name' => 'Judecătoria Bocșa']);
        $sector1 = $courtRepository->findOneBy(['name' => self::SECTOR_1]);
        $this->assertNotNull($holder);
        $this->assertNotNull($sector1);

        $sector1->setPortalCode(null);
        $holder->setPortalCode(self::SECTOR_1_CODE);
        $em->flush();
        $holderId = $holder->getId();

        $tester = $this->runImport();

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Conflict', $tester->getDisplay());
        $this->assertStringContainsString('Nothing was written', $tester->getDisplay());

        $em->clear();
        $reloaded = $courtRepository->find($holderId);
        $this->assertNotNull($reloaded);
        $this->assertSame(self::SECTOR_1_CODE, $reloaded->getPortalCode(), 'A refused import must not write anything.');

        // Restore the codes the rest of the suite expects.
        $reloaded->setPortalCode(null);
        $em->flush();
        $this->runImport();
    }

    public function testCodesSwappedBetweenTwoCourtsAreReassigned(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $courtRepository = static::getContainer()->get(CourtRepository::class);

        $first = $courtRepository->findOneBy(['name' => self::SECTOR_1]);
        $second = $courtRepository->findOneBy(['name' => 'Judecătoria Adjud']);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $secondCode = $second->getPortalCode();
        $this->assertNotNull($secondCode);

        // Swapped codes stay unique overall, but a single-pass flush would trip
        // the unique index halfway through.
        $first->setPortalCode(null);
        $em->flush();
        $second->setPortalCode(self::SECTOR_1_CODE);
        $em->flush();
        $first->setPortalCode($secondCode);
        $em->flush();

        $tester = $this->runImport();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        $em->clear();
        $first = $courtRepository->findOneBy(['name' => self::SECTOR_1]);
        $second = $courtRepository->findOneBy(['name' => 'Judecătoria Adjud']);
        $this->assertSame(self::SECTOR_1_CODE, $first?->getPortalCode());
        $this->assertSame($secondCode, $second?->getPortalCode());
    }

    public function testDryRunLeavesTheDatabaseUntouched(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $courtRepository = static::getContainer()->get(CourtRepository::class);

        $court = $courtRepository->findOneBy(['name' => self::SECTOR_1]);
        $this->assertNotNull($court);
        $court->setPortalCode(null);
        $em->flush();

        $tester = $this->runImport(['--dry-run' => true]);

        $this->assertStringContainsString('Dry run finished', $tester->getDisplay());

        $em->clear();
        $court = $courtRepository->findOneBy(['name' => self::SECTOR_1]);
        $this->assertNotNull($court);
        $this->assertNull($court->getPortalCode(), 'Dry run must not persist portal codes.');

        // Leave the shared database with the codes a normal run would produce.
        $this->runImport();
    }
}
