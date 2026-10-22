<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\CourtEmailAddress;
use App\Entity\FilingAttempt;
use App\Entity\FilingEmailPart;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CourtEmailStatus;
use App\Enum\FilingAttemptOutcome;
use App\Enum\FilingAttemptSource;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartStatus;
use App\Enum\NotificationType;
use App\Message\SendFilingEmailPartMessage;
use App\Tests\Support\CourtFilingReceiverStub;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use App\Tests\Support\RecordingCourtTransport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Worker;

/**
 * The failure policy through a real Worker, with Messenger's retry listener and
 * the failure subscriber on the container's event dispatcher, and the recording
 * SMTP transport told to fail.
 */
final class SendFilingEmailPartWorkerTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;

    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private int $forwarded = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'filing-worker');
        $this->courtTransport()->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->clear();
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testTemporaryFailureIsRetriedOnTheScheduleThenExhausted(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submissionId = (int) $this->submitFiling($this->case, $this->user)->getId();
        $this->courtTransport()->failTimes(4, RecordingCourtTransport::rcptRefused(451, '4.3.0 Try again later'));

        $this->runWorker();

        $retries = array_values(array_filter($this->courtQueue()->getSent(), static fn (Envelope $e): bool => $e->last(RedeliveryStamp::class) !== null));
        self::assertSame([300000, 1800000, 7200000], array_map(static fn (Envelope $e): int => $e->last(DelayStamp::class)?->getDelay() ?? -1, $retries));
        self::assertSame([1, 2, 3], array_map(static fn (Envelope $e): int => $e->last(RedeliveryStamp::class)?->getRetryCount() ?? -1, $retries));
        self::assertSame([], $this->courtTransport()->sent);

        $submission = $this->submission($submissionId);
        $part = $submission->getPart(1);
        self::assertSame(FilingPartStatus::FAILED_TEMPORARY_EXHAUSTED, $part?->getStatus());
        self::assertSame(451, $part->getLastSmtpCode());
        self::assertSame(4, $part->getAttemptCount());
        self::assertSame(FilingDeliveryStatus::FAILED_TEMPORARY_EXHAUSTED, $submission->getDeliveryStatus());
        self::assertNull($submission->getActiveLock());
        self::assertSame(FilingPackageStatus::SIGNED, $submission->getPackage()->getStatus(), 'The signed package stays reusable.');

        $attempts = $this->attempts($part);
        self::assertSame([1, 2, 3, 4], array_map(static fn (FilingAttempt $a): int => $a->getAttemptNo(), $attempts));
        self::assertSame(array_fill(0, 4, FilingAttemptOutcome::TEMPORARY), array_map(static fn (FilingAttempt $a): ?FilingAttemptOutcome => $a->getOutcome(), $attempts));
        foreach ([0, 1, 2] as $i) {
            self::assertNotNull($attempts[$i]->getNextRetryAt(), 'Each retried attempt records when the next try is due.');
        }
        self::assertNull($attempts[3]->getNextRetryAt());

        $notices = $this->notices(NotificationType::FILING_FAILED_TEMPORARY);
        self::assertCount(2, $notices, 'Told at the first failure and when the retries are used up; the retries in between are silent.');
        self::assertStringContainsString(':temporary_retrying:', (string) $notices[0]->getDedupKey());
        self::assertStringContainsString(':temporary_exhausted:', (string) $notices[1]->getDedupKey());
        self::assertStringContainsString('451', $notices[1]->getMessage());
        self::assertStringNotContainsString('@', $notices[0]->getMessage() . $notices[1]->getMessage());
    }

    public function testUnknownCourtRecipientFailsAtOnceAndMarksTheAddressInvalid(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $submissionId = (int) $this->submitFiling($this->case, $this->user)->getId();
        $this->courtTransport()->failTimes(1, RecordingCourtTransport::rcptRefused(550, '5.1.1 User unknown'));

        $this->runWorker();

        self::assertSame([], $this->retryEnvelopes());
        $submission = $this->submission($submissionId);
        $part = $submission->getPart(1);
        self::assertSame(FilingPartStatus::FAILED_PERMANENT, $part?->getStatus());
        self::assertCount(1, $this->attempts($part));
        self::assertSame(FilingDeliveryStatus::FAILED_PERMANENT, $submission->getDeliveryStatus());
        self::assertFalse($submission->isActive());
        self::assertSame(FilingPartStatus::CANCELLED, $submission->getPart(2)?->getStatus(), 'The annexes never leave without the petition.');

        $address = $this->em->find(CourtEmailAddress::class, (int) $submission->getCourtEmailAddress()?->getId());
        self::assertSame(CourtEmailStatus::INVALID, $address?->getStatus());
        self::assertSame($submissionId, $address->getInvalidatedBySubmission()?->getId());

        $notices = $this->notices(NotificationType::FILING_FAILED_PERMANENT);
        self::assertCount(1, $notices);
        self::assertStringContainsString('Serverul destinatarului a respins adresa', $notices[0]->getMessage());
    }

    public function testPolicyRefusalFailsAtOnceAndKeepsTheAddress(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submissionId = (int) $this->submitFiling($this->case, $this->user)->getId();
        $this->courtTransport()->failTimes(1, RecordingCourtTransport::rcptRefused(550, '5.7.1 Message refused by policy'));

        $this->runWorker();

        self::assertSame([], $this->retryEnvelopes());
        $submission = $this->submission($submissionId);
        self::assertSame(FilingDeliveryStatus::FAILED_PERMANENT, $submission->getDeliveryStatus());
        $address = $this->em->find(CourtEmailAddress::class, (int) $submission->getCourtEmailAddress()?->getId());
        self::assertSame(CourtEmailStatus::VALIDATED, $address?->getStatus());
        self::assertNull($address->getInvalidatedBySubmission());
    }

    public function testSizeRefusalFailsAtOnceWithoutRetry(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submissionId = (int) $this->submitFiling($this->case, $this->user)->getId();
        $this->courtTransport()->failTimes(1, RecordingCourtTransport::endOfDataRefused(552, 'Message size exceeds fixed limit'));

        $this->runWorker();

        self::assertSame([], $this->retryEnvelopes());
        $submission = $this->submission($submissionId);
        self::assertSame(FilingPartStatus::FAILED_SIZE, $submission->getPart(1)?->getStatus());
        self::assertSame(FilingDeliveryStatus::FAILED_SIZE, $submission->getDeliveryStatus());
        self::assertFalse($submission->isActive());
        self::assertCount(1, $this->notices(NotificationType::FILING_FAILED_SIZE));
    }

    public function testMissingSignedFileFailsWithoutAnySmtpTry(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        $submissionId = (int) $submission->getId();
        foreach ($submission->getPart(1)?->getPackageParts() ?? [] as $packagePart) {
            unlink($this->filingUploadsDir . '/' . $packagePart->getSignedPath());
            break;
        }

        $this->runWorker();

        self::assertSame([], $this->retryEnvelopes());
        self::assertSame([], $this->courtTransport()->sent);
        $submission = $this->submission($submissionId);
        $part = $submission->getPart(1);
        self::assertSame(FilingPartStatus::FAILED_INTERNAL, $part?->getStatus());
        self::assertSame(0, $part->getAttemptCount(), 'No SMTP try was made, so none was used up.');
        self::assertSame(FilingDeliveryStatus::FAILED_INTERNAL, $submission->getDeliveryStatus());
        self::assertFalse($submission->isActive());
        self::assertCount(1, $this->notices(NotificationType::FILING_FAILED_INTERNAL));
    }

    public function testRedeliveryWhileTheFirstRunSendsDoesNotSendTwice(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $submission = $this->submitFiling($this->case, $this->user);
        $submissionId = (int) $submission->getId();
        $firstId = (int) $submission->getPart(1)?->getId();
        // The same message redelivered while the first run is talking to the server.
        $this->courtTransport()->beforeSend = fn () => $this->handleFilingPart($firstId);

        $this->handleNextFilingMessage();

        self::assertCount(1, $this->courtTransport()->sent);
        $recheck = array_values(array_filter(
            $this->courtQueue()->getSent(),
            static fn (Envelope $e): bool => $e->getMessage() instanceof SendFilingEmailPartMessage && $e->getMessage()->partId === $firstId && $e->last(DelayStamp::class) !== null,
        ));
        self::assertCount(1, $recheck, 'The second run looks again later instead of sending.');
        self::assertSame(180000, $recheck[0]->last(DelayStamp::class)?->getDelay());

        $this->em->clear();
        $submission = $this->submission($submissionId);
        self::assertSame(FilingPartStatus::ACCEPTED, $submission->getPart(1)?->getStatus());
        self::assertSame(FilingDeliveryStatus::PARTIALLY_SENT, $submission->getDeliveryStatus());

        // The recheck finds the part accepted; the next part leaves once.
        $this->handleAllFilingMessages();
        self::assertCount(2, $this->courtTransport()->sent);
        $this->em->clear();
        self::assertSame(FilingDeliveryStatus::SENT, $this->submission($submissionId)->getDeliveryStatus());
    }

    public function testPartFoundMidSendPastTheStaleWindowIsUncertain(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        $part = $submission->getPart(1) ?? throw new \LogicException();
        $part->startAttempt();
        $this->em->persist(new FilingAttempt($part, 1, FilingAttemptSource::SMTP, new \DateTimeImmutable('-1 hour')));
        $this->em->flush();

        $this->handleAllFilingMessages();

        self::assertSame([], $this->courtTransport()->sent);
        $this->em->clear();
        $submission = $this->submission((int) $submission->getId());
        $part = $submission->getPart(1);
        self::assertSame(FilingPartStatus::UNCERTAIN, $part?->getStatus());
        self::assertSame(FilingDeliveryStatus::NEEDS_REVIEW, $submission->getDeliveryStatus());
        self::assertSame('filing.status.review.part_uncertain', $submission->getReviewReason());
        self::assertTrue($submission->isActive());
        self::assertSame([1, 2], array_map(static fn (FilingAttempt $a): int => $a->getAttemptNo(), $this->attempts($part)));
        self::assertCount(1, $this->notices(NotificationType::FILING_NEEDS_REVIEW));
    }

    public function testPartFoundMidSendInsideTheStaleWindowIsLookedAtAgainLater(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        $part = $submission->getPart(1) ?? throw new \LogicException();
        $part->startAttempt();
        $this->em->persist(new FilingAttempt($part, 1, FilingAttemptSource::SMTP, new \DateTimeImmutable()));
        $this->em->flush();

        $this->handleNextFilingMessage();

        self::assertSame([], $this->courtTransport()->sent);
        $this->em->clear();
        $part = $this->em->find(FilingEmailPart::class, (int) $part->getId());
        self::assertSame(FilingPartStatus::SENDING, $part?->getStatus());
        self::assertCount(1, $this->attempts($part));
        $queued = [...$this->courtQueue()->getSent()];
        self::assertSame(180000, end($queued)->last(DelayStamp::class)?->getDelay());
    }

    /** Feeds the queued envelopes to a real Worker until nothing new is queued. */
    private function runWorker(): void
    {
        $container = static::getContainer();
        $dispatcher = $container->get('event_dispatcher');
        $receiver = new CourtFilingReceiverStub();
        $stopWhenEmpty = static function (WorkerRunningEvent $event) use ($receiver): void {
            if ($receiver->isEmpty()) {
                $event->getWorker()->stop();
            }
        };
        $dispatcher->addListener(WorkerRunningEvent::class, $stopWhenEmpty);

        try {
            for ($round = 0; $round < 10; ++$round) {
                $sent = $this->courtQueue()->getSent();
                $new = array_slice($sent, $this->forwarded);
                $this->forwarded = count($sent);
                if ($new === []) {
                    break;
                }
                $receiver->push(...$new);
                (new Worker(['court_filing' => $receiver], $container->get(MessageBusInterface::class), $dispatcher))->run(['sleep' => 1000]);
            }
        } finally {
            $dispatcher->removeListener(WorkerRunningEvent::class, $stopWhenEmpty);
        }
        $this->em->clear();
    }

    /** @return list<Envelope> */
    private function retryEnvelopes(): array
    {
        return array_values(array_filter($this->courtQueue()->getSent(), static fn (Envelope $e): bool => $e->last(RedeliveryStamp::class) !== null));
    }

    private function submission(int $id): FilingSubmission
    {
        return $this->em->find(FilingSubmission::class, $id) ?? throw new \LogicException('Submission not found.');
    }

    /** @return list<FilingAttempt> */
    private function attempts(FilingEmailPart $part): array
    {
        return $this->em->getRepository(FilingAttempt::class)->findBy(['part' => $part], ['attemptNo' => 'ASC']);
    }

    /** @return list<Notification> */
    private function notices(NotificationType $type): array
    {
        return $this->em->getRepository(Notification::class)->findBy(['user' => $this->user->getId(), 'type' => $type->value], ['id' => 'ASC']);
    }
}
