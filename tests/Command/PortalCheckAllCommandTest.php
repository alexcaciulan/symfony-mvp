<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Message\CheckCasePortalMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PortalCheckAllCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->testPrefix = 'portal-cmd-' . uniqid();
    }

    private function transport(): InMemoryTransport
    {
        /** @var InMemoryTransport $t */
        $t = static::getContainer()->get('messenger.transport.async');

        return $t;
    }

    /** @return CheckCasePortalMessage[] */
    private function dispatchedMessages(): array
    {
        return array_values(array_filter(
            array_map(static fn ($env) => $env->getMessage(), $this->transport()->getSent()),
            static fn ($m): bool => $m instanceof CheckCasePortalMessage,
        ));
    }

    private function createEligibleCase(): LegalCase
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($this->testPrefix . '-' . uniqid() . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'test'));
        $user->setIsVerified(true);
        $this->em->persist($user);

        $court = new Court();
        $court->setName('Cmd Court ' . $this->testPrefix . '-' . uniqid());
        $court->setCounty('CJ');
        $court->setType(CourtType::JUDECATORIE);
        $court->setPortalCode('CmdCourt' . uniqid());
        $this->em->persist($court);

        $case = new LegalCase();
        $case->setUser($user);
        $case->setCourt($court);
        $case->setStatus(CaseStatus::DOSAR_INREGISTRAT);
        $case->setCourtCaseNumber('700/211/2026');
        $case->setPortalMonitoringActive(true);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    private function runCommand(array $input = []): void
    {
        $command = (new Application(self::$kernel))->find('app:portal-check-all');
        (new CommandTester($command))->execute($input);
    }

    public function testDispatchesMessageForEligibleCase(): void
    {
        $case = $this->createEligibleCase();

        $this->runCommand();

        $ids = array_map(static fn (CheckCasePortalMessage $m): int => $m->caseId, $this->dispatchedMessages());
        $this->assertContains($case->getId(), $ids);
    }

    public function testCaseIdOptionDispatchesSingleMessage(): void
    {
        $case = $this->createEligibleCase();

        $this->runCommand(['--case-id' => (string) $case->getId()]);

        $messages = $this->dispatchedMessages();
        $this->assertCount(1, $messages);
        $this->assertSame($case->getId(), $messages[0]->caseId);
    }

    public function testUnknownCaseIdDispatchesNothing(): void
    {
        $this->runCommand(['--case-id' => '99999999']);

        $this->assertCount(0, $this->dispatchedMessages());
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE ?", ['Cmd Court ' . $this->testPrefix . '%']);
        parent::tearDown();
    }
}
