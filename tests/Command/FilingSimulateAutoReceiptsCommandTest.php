<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\FilingSimulateAutoReceiptsCommand;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingEvidenceStatus;
use App\Repository\AuditLogRepository;
use App\Repository\FilingSubmissionRepository;
use App\Service\AuditLogService;
use App\Service\Filing\RecipientOverrideGuard;
use App\Service\Inbound\CourtReplySimulator;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class FilingSimulateAutoReceiptsCommandTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;

    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    /** @var list<array{0: RawMessage, 1: ?Envelope}> */
    private array $written = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'auto-receipt');
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $this->courtTransport()->reset();
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleFilingPart((int) $submission->getPart(1)?->getId());
        static::getContainer()->get('security.token_storage')->setToken(null);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?', [LegalCase::class, (string) $this->case->getId()]);
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testEveryRecentSubmissionAwaitingAReceiptGetsOneWhenEnabled(): void
    {
        $tester = $this->tester(enabled: true);

        self::assertSame(0, $tester->execute(['--min-age' => '0']));

        self::assertStringContainsString('Simulated 1 court receipt(s).', $tester->getDisplay());
        self::assertCount(1, $this->written);
        [$message, $envelope] = $this->written[0];
        self::assertSame('depuneri@lexrecovery.test', $envelope?->getRecipients()[0]->getAddress());
        self::assertStringContainsString('In-Reply-To:', $message->toString());

        // Answered once, even while the poll has not read the receipt yet.
        self::assertSame(0, $this->tester(enabled: true)->execute(['--min-age' => '0']));
        self::assertCount(1, $this->written);
    }

    public function testASubmissionThatAlreadyHasItsReceiptIsLeftAlone(): void
    {
        $submission = static::getContainer()->get(FilingSubmissionRepository::class)->findLatestForCase($this->case);
        $submission->markReceipt(new \DateTimeImmutable(), null, FilingEvidenceStatus::RECEIPT_RECEIVED);
        $this->em->flush();

        self::assertSame(0, $this->tester(enabled: true)->execute(['--min-age' => '0']));

        self::assertSame([], $this->written);
    }

    public function testNothingIsWrittenWhenTheSimulatorItselfRefuses(): void
    {
        $tester = $this->tester(enabled: true, simulatorEnabled: false);

        self::assertSame(0, $tester->execute(['--min-age' => '0']));

        self::assertStringContainsString('INBOUND_SIMULATOR_ENABLED', $tester->getDisplay());
        self::assertSame([], $this->written);
    }

    public function testAPetitionSentLessThanMinAgeAgoWaitsForTheNextRound(): void
    {
        $tester = $this->tester(enabled: true);

        self::assertSame(0, $tester->execute(['--min-age' => '3600']));

        self::assertStringContainsString('Simulated 0 court receipt(s).', $tester->getDisplay());
        self::assertSame([], $this->written);
    }

    public function testNothingIsWrittenWhenAutoReceiptsAreOff(): void
    {
        $tester = $this->tester(enabled: false);

        self::assertSame(0, $tester->execute(['--min-age' => '0']));

        self::assertStringContainsString('INBOUND_AUTO_RECEIPT is off', $tester->getDisplay());
        self::assertSame([], $this->written);
    }

    private function tester(bool $enabled, bool $simulatorEnabled = true): CommandTester
    {
        $written = &$this->written;
        $transport = new class($written) implements TransportInterface {
            /** @param list<array{0: RawMessage, 1: ?Envelope}> $written */
            public function __construct(private array &$written) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->written[] = [$message, $envelope];

                return null;
            }

            public function __toString(): string
            {
                return 'recording://';
            }
        };
        $simulator = new CourtReplySimulator($transport, new RecipientOverrideGuard(false, 'instanta-simulata@example.test'), $simulatorEnabled, 'dev', 'mx.lexrecovery.test', 'depuneri@lexrecovery.test', 'Delivered-To');

        $container = static::getContainer();

        return new CommandTester(new FilingSimulateAutoReceiptsCommand(
            $simulator,
            $container->get(FilingSubmissionRepository::class),
            $container->get(AuditLogRepository::class),
            $container->get(AuditLogService::class),
            $this->em,
            $enabled,
        ));
    }
}
