<?php

namespace App\Tests\Command;

use App\Entity\LegalCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SeedDemoCasesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Pre-requisites: import-courts + create-test-users must run first
        $kernel = self::$kernel;
        $app = new Application($kernel);
        $app->setAutoExit(false);

        (new CommandTester($app->find('app:import-courts')))->execute(['--no-interaction' => true]);
        (new CommandTester($app->find('app:create-test-users')))->execute(['--no-interaction' => true]);
    }

    public function testCommandCreatesFiveCases(): void
    {
        $this->runSeedCommand();

        $cases = $this->em->getRepository(LegalCase::class)
            ->createQueryBuilder('c')
            ->where('c.caseNumber LIKE :prefix')
            ->setParameter('prefix', 'LR-DEMO-%')
            ->getQuery()
            ->getResult();

        $this->assertCount(5, $cases);
    }

    public function testCommandIsIdempotent(): void
    {
        $this->runSeedCommand();
        $this->runSeedCommand();

        $cases = $this->em->getRepository(LegalCase::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.caseNumber LIKE :prefix')
            ->setParameter('prefix', 'LR-DEMO-%')
            ->getQuery()
            ->getSingleScalarResult();

        $this->assertSame(5, (int) $cases);
    }

    private function runSeedCommand(): void
    {
        $kernel = self::$kernel;
        $app = new Application($kernel);
        $app->setAutoExit(false);
        $tester = new CommandTester($app->find('app:seed-demo-cases'));
        $exit = $tester->execute(['--no-interaction' => true]);
        $this->assertSame(0, $exit, $tester->getDisplay());
    }

    protected function tearDown(): void
    {
        if (!isset($this->em)) {
            parent::tearDown();
            return;
        }

        $conn = $this->em->getConnection();
        // Clean in FK-respecting order
        $conn->executeStatement("DELETE ld FROM legal_deadline ld JOIN legal_case lc ON ld.legal_case_id = lc.id WHERE lc.case_number LIKE 'LR-DEMO-%'");
        $conn->executeStatement("DELETE d FROM debtor d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.case_number LIKE 'LR-DEMO-%'");
        $conn->executeStatement("DELETE FROM legal_case WHERE case_number LIKE 'LR-DEMO-%'");
        $conn->executeStatement("DELETE c FROM creditor c JOIN user u ON c.user_id = u.id WHERE u.email = 'avocat@test.com' AND c.cui = 'RO12345678'");
        parent::tearDown();
    }
}
