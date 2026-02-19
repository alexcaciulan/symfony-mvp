<?php

namespace App\Tests\Command;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CreateTestUsersCommandTest extends KernelTestCase
{
    public function testCreateTestUsers(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('app:create-test-users');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Test users', $output);

        $userRepository = static::getContainer()->get(UserRepository::class);

        $admin = $userRepository->findOneBy(['email' => 'admin@test.com']);
        $this->assertNotNull($admin);
        $this->assertContains('ROLE_ADMIN', $admin->getRoles());
        $this->assertTrue($admin->isVerified());

        $creditorPf = $userRepository->findOneBy(['email' => 'creditor-pf@test.com']);
        $this->assertNotNull($creditorPf);
        $this->assertContains('ROLE_CREDITOR', $creditorPf->getRoles());

        $creditorPj = $userRepository->findOneBy(['email' => 'creditor-pj@test.com']);
        $this->assertNotNull($creditorPj);
        $this->assertNotNull($creditorPj->getCui());
        $this->assertNotNull($creditorPj->getCompanyName());
    }

    public function testCreateTestUsersIsIdempotent(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $command = $application->find('app:create-test-users');

        // Run first time
        $tester1 = new CommandTester($command);
        $tester1->execute([]);
        $tester1->assertCommandIsSuccessful();

        $userRepository = static::getContainer()->get(UserRepository::class);
        $countAfterFirst = $userRepository->count([]);

        // Run second time
        $tester2 = new CommandTester($command);
        $tester2->execute([]);
        $tester2->assertCommandIsSuccessful();

        $countAfterSecond = $userRepository->count([]);
        $this->assertSame($countAfterFirst, $countAfterSecond);
    }
}
