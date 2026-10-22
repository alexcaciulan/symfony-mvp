<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\FilingPreflightCommand;
use App\Service\Filing\RecipientOverrideGuard;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FilingPreflightCommandTest extends KernelTestCase
{
    public function testPassesWithTheDefaultConfiguration(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:filing:preflight'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('consistent', $tester->getDisplay());
    }

    public function testFailsInLiveModeWithAnOverrideStillSet(): void
    {
        $tester = new CommandTester($this->command(true, 'simulare@example.test'));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('COURT_FILING_RECIPIENT_OVERRIDE', $tester->getDisplay());
    }

    public function testFailsOutsideLiveModeWithoutAnOverride(): void
    {
        $tester = new CommandTester($this->command(false, ''));

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testWarnsWhenLiveModePointsAtTheTestServer(): void
    {
        $tester = new CommandTester($this->command(true, '', 'smtp://mailer:1025'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('local test server', $tester->getDisplay());
    }

    private function command(bool $live, string $override, string $dsn = 'smtp://smtp.example.test:587'): FilingPreflightCommand
    {
        return new FilingPreflightCommand(new RecipientOverrideGuard($live, $override), 'depuneri@lexrecovery.test', 'lexrecovery.test', $dsn);
    }
}
