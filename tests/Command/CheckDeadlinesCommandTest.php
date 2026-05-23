<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Threshold / auto-finalization logic is covered deterministically (with a fixed
 * date) in DeadlineAlertServiceTest + CaseAutoFinalizerTest. This is just a smoke
 * test: the command runs and produces valid output in both formats.
 */
class CheckDeadlinesCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        $command = (new Application(self::bootKernel()))->find('app:check-deadlines');

        return new CommandTester($command);
    }

    public function testRunsTextFormat(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Deadline check', $tester->getDisplay());
    }

    public function testJsonFormatIsValid(): void
    {
        $tester = $this->tester();
        $tester->execute(['--format' => 'json']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('alerts', $payload);
        $this->assertArrayHasKey('autoFinalize', $payload);
        $this->assertArrayHasKey('total', $payload['alerts']);
        $this->assertArrayHasKey('finalized', $payload['autoFinalize']);
    }
}
