<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Selection logic is covered in SubscriptionRepositoryTest. This is a smoke test:
 * the command runs, honours its grace window and rejects nonsense input.
 */
class ExpireUnpaidSubscriptionsCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        $command = (new Application(self::bootKernel()))->find('app:subscriptions:expire-unpaid');

        return new CommandTester($command);
    }

    public function testRunsWithDefaultGracePeriod(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Expire unpaid subscriptions', $tester->getDisplay());
    }

    public function testRejectsNonPositiveGraceDays(): void
    {
        $tester = $this->tester();
        $tester->execute(['--grace-days' => '0']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
