<?php

namespace App\Tests\Command;

use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class MonitorCourtCasesCommandTest extends KernelTestCase
{
    public function testCommandRunsWithNoCases(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:monitor-court-cases');
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Found', $output);
        $this->assertStringContainsString('case(s) to monitor', $output);
        $this->assertStringContainsString('Monitoring complete', $output);
    }

    public function testCommandAcceptsCaseIdOption(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:monitor-court-cases');
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--case-id' => '99999']);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Found 0 case(s) to monitor', $output);
    }

    public function testCommandAcceptsDelayOption(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:monitor-court-cases');
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--delay' => '500']);

        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testCommandFindsEligibleMonitorableCase(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $prefix = 'cmd-mon-' . uniqid();
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($prefix . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'test'));
        $user->setIsVerified(true);
        $em->persist($user);

        $court = new Court();
        $court->setName('Cmd Court ' . $prefix);
        $court->setCounty('CJ');
        $court->setType(CourtType::JUDECATORIE);
        $court->setPortalCode('CmdCourt' . uniqid());
        $em->persist($court);

        $case = new LegalCase();
        $case->setUser($user);
        $case->setCourt($court);
        $case->setStatus(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('700/211/2026');
        $case->setPortalMonitoringActive(true);
        $em->persist($case);
        $em->flush();

        // Evită apelul SOAP real: înlocuim clientul pe instanța partajată folosită
        // de comandă.
        $monitoring = $container->get(\App\Service\Portal\CaseMonitoringService::class);
        $stub = $this->createStub(\App\Service\Portal\PortalJustClient::class);
        $stub->method('searchByCaseNumber')->willReturn([]);
        (new \ReflectionClass($monitoring))->getProperty('portalClient')->setValue($monitoring, $stub);

        $application = new Application($kernel);
        $command = $application->find('app:monitor-court-cases');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['--delay' => '0']);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('700/211/2026', $output);

        $conn = $em->getConnection();
        $conn->executeStatement("DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?", [$prefix . '%']);
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$prefix . '%']);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE ?", ['Cmd Court ' . $prefix . '%']);
    }
}
