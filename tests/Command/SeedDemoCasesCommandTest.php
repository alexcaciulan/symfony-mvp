<?php

namespace App\Tests\Command;

use App\Entity\Creditor;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\PersonType;
use App\Util\PiiMasker;
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

    public function testTheDemoDataCarriesValidCuis(): void
    {
        $lawyer = $this->em->getRepository(User::class)->findOneBy(['email' => 'avocat@test.com']);
        $legacy = new Creditor();
        $legacy->setUser($lawyer);
        $legacy->setPersonType(PersonType::PJ);
        $legacy->setName('Demo Recovery SRL');
        $legacy->setCui('RO12345678');
        $legacy->setAddress('Bd. Demo 100, București, Sector 1');
        $this->em->persist($legacy);
        $this->em->flush();

        $this->runSeedCommand();

        $this->em->clear();
        $creditor = $this->em->getRepository(Creditor::class)->find($legacy->getId());
        $this->assertTrue(PiiMasker::isValidCui(preg_replace('/\D/', '', (string) $creditor->getCui())), 'an earlier seed\'s creditor is corrected');
        $cases = $this->em->getRepository(LegalCase::class)->createQueryBuilder('c')
            ->where('c.caseNumber LIKE :prefix')->setParameter('prefix', 'LR-DEMO-%')
            ->getQuery()->getResult();
        foreach ($cases as $case) {
            $cui = $case->getPrimaryDebtor()?->getCui();
            if ($cui !== null) {
                $this->assertTrue(PiiMasker::isValidCui(preg_replace('/\D/', '', $cui)), $cui);
            }
        }
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
        // Clean in FK-respecting order. Documents reference legal_case (Pas 3.0
        // command now seeds a demo Document on the first case for case-view UX).
        $conn->executeStatement("DELETE d FROM document d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.case_number LIKE 'LR-DEMO-%'");
        $conn->executeStatement("DELETE ld FROM legal_deadline ld JOIN legal_case lc ON ld.legal_case_id = lc.id WHERE lc.case_number LIKE 'LR-DEMO-%'");
        // Other commands under test (missing communication date) notify on any case, demo ones included.
        $conn->executeStatement("DELETE n FROM notification n JOIN legal_case lc ON n.legal_case_id = lc.id WHERE lc.case_number LIKE 'LR-DEMO-%'");
        $conn->executeStatement("DELETE l FROM legal_case_debtor l JOIN legal_case lc ON l.legal_case_id = lc.id WHERE lc.case_number LIKE 'LR-DEMO-%'");
        $conn->executeStatement("DELETE FROM legal_case WHERE case_number LIKE 'LR-DEMO-%'");
        $conn->executeStatement("DELETE c FROM creditor c JOIN user u ON c.user_id = u.id WHERE u.email = 'avocat@test.com' AND c.cui IN ('RO12345674', 'RO12345678')");
        parent::tearDown();
    }
}
