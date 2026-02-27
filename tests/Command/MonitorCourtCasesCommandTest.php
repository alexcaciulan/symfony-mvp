<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

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
}
