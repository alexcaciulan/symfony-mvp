<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\InboundMail;
use App\Entity\InboundPollHeartbeat;
use App\Entity\LegalCase;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\InboundClassification;
use App\Enum\NotificationType;
use App\Command\InboundHeartbeatCheckCommand;
use App\Repository\InboundMailRepository;
use App\Repository\InboundPollHeartbeatRepository;
use App\Repository\UserRepository;
use App\Service\Filing\RecipientOverrideGuard;
use App\Service\Notification\NotificationDispatcherInterface;
use App\Service\Inbound\CourtReplySimulator;
use App\Service\Inbound\InboundRawStorage;
use App\Tests\Support\FilingCaseFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InboundCommandsTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;

    private EntityManagerInterface $em;
    private User $user;
    private ?User $admin = null;
    private ?LegalCase $case = null;
    /** @var list<int> */
    private array $mailIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'inbound-cmd');
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->clear();
            $conn = $this->em->getConnection();
            if ($this->mailIds !== []) {
                $ids = implode(',', $this->mailIds);
                foreach ($conn->fetchFirstColumn('SELECT raw_path FROM inbound_mail WHERE id IN (' . $ids . ')') as $path) {
                    @unlink($this->filingUploadsDir . '/' . $path);
                }
                $conn->executeStatement('DELETE FROM inbound_mail WHERE id IN (' . $ids . ')');
                $conn->executeStatement("DELETE FROM audit_log WHERE action = 'inbound_mail_purged' AND entity_id IN ('" . implode("','", $this->mailIds) . "')");
            }
            if ($this->admin !== null) {
                $conn->executeStatement('DELETE FROM notification WHERE user_id = ?', [$this->admin->getId()]);
                $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$this->admin->getId()]);
            }
            $this->removeFilingFixtures($this->em, $this->user, $this->case);
        }
        parent::tearDown();
    }

    public function testPurgeDeletesOnlyUnattributedAndSpamPastTheirDateWithTheirFiles(): void
    {
        $this->case = $this->createFilingCase($this->em, $this->user);
        $past = new \DateTimeImmutable('-1 day');
        $unknown = $this->mail(InboundClassification::UNKNOWN, $past);
        $spam = $this->mail(InboundClassification::SPAM, $past);
        $notYet = $this->mail(InboundClassification::UNKNOWN, new \DateTimeImmutable('+5 days'));
        $linked = $this->mail(InboundClassification::UNKNOWN, $past, $this->case);
        [$unknownId, $spamId, $notYetId, $linkedId] = [$unknown->getId(), $spam->getId(), $notYet->getId(), $linked->getId()];
        $storage = static::getContainer()->get(InboundRawStorage::class);
        $unknownPath = (string) $storage->verifiedPath($unknown);
        self::assertFileExists($unknownPath);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:filing:inbound-purge'));
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertNotNull($this->em->getRepository(InboundMail::class)->find($unknownId), 'A dry run deletes nothing.');

        self::assertSame(0, $tester->execute([]));
        $this->em->clear();
        $repo = $this->em->getRepository(InboundMail::class);
        self::assertNull($repo->find($unknownId));
        self::assertNull($repo->find($spamId));
        self::assertNotNull($repo->find($notYetId));
        self::assertNotNull($repo->find($linkedId), 'A message tied to a case waits for the retention rule.');
        self::assertFileDoesNotExist($unknownPath);
        self::assertNotNull($storage->verifiedPath($repo->find($linkedId)));
    }

    public function testHeartbeatAlertsAdminsWhenTheLastSuccessIsStaleAndPassesWhenFresh(): void
    {
        $this->admin = new User();
        $this->admin->setEmail('inbound-admin-' . uniqid() . '@test.com');
        $this->admin->setPassword('not-used');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($this->admin);
        $heartbeat = $this->em->getRepository(InboundPollHeartbeat::class)->findOneBy([], ['id' => 'ASC']) ?? new InboundPollHeartbeat();
        $this->em->persist($heartbeat);
        $heartbeat->succeeded(new \DateTimeImmutable('-5 hours'), 0);
        $this->em->flush();
        // The admin list is narrowed to this test's admin: the shared test database
        // keeps admins left by other suites.
        $users = $this->createStub(UserRepository::class);
        $users->method('findAdmins')->willReturn([$this->admin]);
        $container = static::getContainer();
        $command = new InboundHeartbeatCheckCommand(
            $container->get(InboundPollHeartbeatRepository::class),
            $container->get(InboundMailRepository::class),
            $users,
            $container->get(NotificationDispatcherInterface::class),
            $container->get(TranslatorInterface::class),
            $container->get(ClockInterface::class),
            new NullLogger(),
            120,
        );
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute([]));
        self::assertSame(1, $tester->execute([]), 'Still stale.');
        $alerts = $this->em->getRepository(Notification::class)->findBy(['user' => $this->admin->getId(), 'type' => NotificationType::INBOUND_POLL_STALE->value]);
        self::assertCount(1, $alerts, 'Once a day per admin.');

        $heartbeat->succeeded(new \DateTimeImmutable('-5 minutes'), 2);
        $this->em->flush();
        self::assertSame(0, $tester->execute([]));
    }

    public function testHeartbeatAlertsAdminsAboutMessagesLeftUnprocessed(): void
    {
        $this->admin = new User();
        $this->admin->setEmail('inbound-admin-' . uniqid() . '@test.com');
        $this->admin->setPassword('not-used');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($this->admin);
        $heartbeat = $this->em->getRepository(InboundPollHeartbeat::class)->findOneBy([], ['id' => 'ASC']) ?? new InboundPollHeartbeat();
        $this->em->persist($heartbeat);
        $heartbeat->succeeded(new \DateTimeImmutable('-5 minutes'), 1);
        $mail = $this->mail(InboundClassification::UNKNOWN, new \DateTimeImmutable('+5 days'));
        $this->em->getConnection()->executeStatement('UPDATE inbound_mail SET processed_at = NULL, created_at = ? WHERE id = ?', [(new \DateTimeImmutable('-40 minutes'))->format('Y-m-d H:i:s'), $mail->getId()]);
        $this->em->flush();
        $users = $this->createStub(UserRepository::class);
        $users->method('findAdmins')->willReturn([$this->admin]);
        $container = static::getContainer();
        $tester = new CommandTester(new InboundHeartbeatCheckCommand(
            $container->get(InboundPollHeartbeatRepository::class),
            $container->get(InboundMailRepository::class),
            $users,
            $container->get(NotificationDispatcherInterface::class),
            $container->get(TranslatorInterface::class),
            $container->get(ClockInterface::class),
            new NullLogger(),
            120,
        ));

        self::assertSame(1, $tester->execute([]), 'A fresh poll does not hide a message nobody processed.');
        self::assertSame(1, $tester->execute([]));
        $alerts = $this->em->getRepository(Notification::class)->findBy(['user' => $this->admin->getId(), 'type' => NotificationType::INBOUND_UNPROCESSED->value]);
        self::assertCount(1, $alerts, 'Once a day per admin.');

        $this->em->getConnection()->executeStatement('UPDATE inbound_mail SET processed_at = NOW() WHERE id = ?', [$mail->getId()]);
        self::assertSame(0, $tester->execute([]));
    }

    public function testSimulatorRefusesWhenDisabled(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:filing:simulate-court-reply'));

        self::assertSame(1, $tester->execute(['--submission' => '1', '--kind' => 'receipt']));
        self::assertStringContainsString('INBOUND_SIMULATOR_ENABLED', $tester->getDisplay());
    }

    public function testSimulatorRefusesInProdOrWithoutTheRecipientOverride(): void
    {
        $simulator = static fn (bool $live, string $override, string $env): CourtReplySimulator => new CourtReplySimulator(new NullTransport(), new RecipientOverrideGuard($live, $override), true, $env, 'mx.lexrecovery.test', 'depuneri@lexrecovery.test', 'Delivered-To');

        self::assertNull($simulator(false, 'instanta-simulata@example.test', 'dev')->refusal());
        self::assertNotNull($simulator(false, 'instanta-simulata@example.test', 'prod')->refusal());
        self::assertNotNull($simulator(true, '', 'dev')->refusal());
        self::assertNotNull($simulator(false, '', 'dev')->refusal());
    }

    private function mail(InboundClassification $class, \DateTimeImmutable $purgeAfter, ?LegalCase $case = null): InboundMail
    {
        $bytes = "Subject: purge\r\n\r\n" . bin2hex(random_bytes(8));
        $path = static::getContainer()->get(InboundRawStorage::class)->write($bytes, 'eml', new \DateTimeImmutable());
        $mail = new InboundMail('fixture', 'purge-' . bin2hex(random_bytes(6)), $path, hash('sha256', $bytes), strlen($bytes), new \DateTimeImmutable(), new \DateTimeImmutable());
        $mail->setClassification($class);
        $mail->markProcessed(new \DateTimeImmutable(), $purgeAfter);
        if ($case !== null) {
            $ref = new \ReflectionProperty(InboundMail::class, 'legalCase');
            $ref->setValue($mail, $case);
        }
        $this->em->persist($mail);
        $this->em->flush();
        $this->mailIds[] = (int) $mail->getId();

        return $mail;
    }
}
