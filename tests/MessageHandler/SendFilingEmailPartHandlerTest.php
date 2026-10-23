<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\CaseStatusHistory;
use App\Entity\FilingSubmission;
use App\Entity\FilingAttempt;
use App\Entity\LegalCase;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Enum\FilingAttemptOutcome;
use App\Enum\FilingChannel;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartStatus;
use App\Enum\NotificationType;
use App\Repository\LegalDeadlineRepository;
use App\Service\Case\CaseWorkflowService;
use App\Service\Deadline\DeadlineService;
use App\Service\Filing\FilingPartOutcomeApplier;
use App\Service\Filing\FilingSubmissionService;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use App\Tests\Support\RecordingCourtTransport;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class SendFilingEmailPartHandlerTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;

    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'filing-handler');
        $this->courtTransport()->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testSinglePartIsSentAndTheCaseMovesWithoutAFilingDate(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);

        $this->handleAllFilingMessages();

        $this->em->refresh($submission);
        $part = $submission->getPart(1);
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());
        self::assertFalse($submission->isActive());
        self::assertSame(FilingPartStatus::ACCEPTED, $part?->getStatus());
        self::assertNotNull($submission->getSentAt());

        // What left is exactly what is on disk.
        $sent = $this->courtTransport()->sent;
        self::assertCount(1, $sent);
        self::assertSame($part->getEmlSha256(), hash('sha256', $sent[0]['bytes']));
        self::assertSame($part->getEmlSha256(), hash_file('sha256', $this->filingUploadsDir . '/' . $part->getEmlPath()));
        self::assertSame(strlen($sent[0]['bytes']), $part->getEncodedBytes());

        // Envelope: VERP sender, the override instead of the court, and the lawyer.
        $envelope = $sent[0]['envelope'];
        self::assertStringStartsWith('bounces+' . $part->getVerpToken() . '@', $envelope->getSender()->getAddress());
        self::assertSame(['instanta-simulata@example.test', $this->user->getEmail()], array_map(static fn ($a): string => $a->getAddress(), $envelope->getRecipients()));

        $this->em->refresh($this->case);
        self::assertSame(CaseStatus::CERERE_DEPUSA, $this->case->getStatus());
        self::assertNull($this->case->getFiledAt());
        self::assertSame(FilingChannel::EMAIL, $this->case->getFilingChannel());
        self::assertSame($part->getMessageId(), $this->case->getFilingReference());
        self::assertSame(FilingPackageStatus::SENT, $submission->getPackage()->getStatus());

        $history = $this->em->getRepository(CaseStatusHistory::class)->findOneBy(['legalCase' => $this->case, 'newStatus' => CaseStatus::CERERE_DEPUSA->value]);
        self::assertSame($this->user->getId(), $history?->getCreatedBy()?->getId());

        // The lawyer hears "transmitted, no receipt", never a separate "filed" notice.
        $types = array_map(static fn (Notification $n): string => $n->getType(), $this->em->getRepository(Notification::class)->findBy(['user' => $this->user]));
        self::assertSame([NotificationType::FILING_SENT->value], $types);
    }

    public function testPartsLeaveInOrderInOneThread(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 3);
        $submission = $this->submitFiling($this->case, $this->user);
        self::assertCount(1, [...$this->courtQueue()->get()]);

        $this->handleNextFilingMessage();
        $this->em->refresh($submission);
        self::assertSame(FilingDeliveryStatus::PARTIALLY_SENT, $submission->getDeliveryStatus());
        self::assertTrue($submission->isActive());
        self::assertSame(CaseStatus::CERERE_DEPUSA, $this->case->getStatus());
        self::assertCount(1, [...$this->courtQueue()->get()], 'Only the next part is queued.');

        $this->handleAllFilingMessages();
        $this->em->refresh($submission);
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());
        self::assertFalse($submission->isActive());

        $sent = $this->courtTransport()->sent;
        self::assertCount(3, $sent);
        $firstId = $submission->getPart(1)?->getMessageId();
        self::assertStringNotContainsString('In-Reply-To', $sent[0]['bytes']);
        foreach ([1, 2] as $i) {
            self::assertStringContainsString('(partea ' . ($i + 1) . ' din 3)', $sent[$i]['bytes']);
            self::assertStringContainsString('In-Reply-To: ' . $firstId, $sent[$i]['bytes']);
            self::assertStringContainsString('References: ' . $firstId, $sent[$i]['bytes']);
        }
    }

    public function testFailedConditionInTheWorkerStopsWithoutSending(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->case->setCourtCaseNumber('123/301/2026');
        $this->em->flush();

        $this->handleAllFilingMessages();

        $this->em->refresh($submission);
        self::assertSame([], $this->courtTransport()->sent);
        self::assertSame(FilingDeliveryStatus::NEEDS_REVIEW, $submission->getDeliveryStatus());
        self::assertSame('filing.send.blocker.court_case_number', $submission->getReviewReason());
        self::assertSame(FilingPartStatus::PENDING, $submission->getPart(1)?->getStatus());
        self::assertSame(CaseStatus::CERERE_GENERATA, $this->case->getStatus());
    }

    public function testPartFoundMidSendIsUncertainAndNotResent(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        $submission->getPart(1)?->setStatus(FilingPartStatus::SENDING);
        $this->em->flush();

        $this->handleAllFilingMessages();

        $this->em->refresh($submission);
        self::assertSame([], $this->courtTransport()->sent);
        self::assertSame(FilingPartStatus::UNCERTAIN, $submission->getPart(1)?->getStatus());
        self::assertSame(FilingDeliveryStatus::NEEDS_REVIEW, $submission->getDeliveryStatus());
        self::assertTrue($submission->isActive(), 'An uncertain part keeps the case held.');
    }

    public function testConnectionFailureBeforeDataSchedulesRetry(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->courtTransport()->failNext = RecordingCourtTransport::connectionRefused();

        try {
            $this->handleNextFilingMessage();
            self::fail('The failure must surface to the worker.');
        } catch (HandlerFailedException $e) {
            // Retryable: the original transport error, not an unrecoverable one.
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }

        $this->em->refresh($submission);
        $part = $submission->getPart(1);
        self::assertSame(FilingPartStatus::RETRY_SCHEDULED, $part?->getStatus());
        self::assertSame(1, $part->getAttemptCount());
        self::assertTrue($submission->isActive());
        $attempts = $this->em->getRepository(FilingAttempt::class)->findBy(['part' => $part]);
        self::assertCount(1, $attempts);
        self::assertSame(FilingAttemptOutcome::TEMPORARY, $attempts[0]->getOutcome());
        self::assertSame(CaseStatus::CERERE_GENERATA, $this->case->getStatus());
    }

    public function testConnectionLostAfterTheWholeMessageIsUncertainAndNeverRetried(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->courtTransport()->failNext = RecordingCourtTransport::lostAfterData();

        try {
            $this->handleNextFilingMessage();
            self::fail('The failure must surface to the worker.');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $e->getPrevious());
        }

        $this->em->refresh($submission);
        self::assertSame(FilingPartStatus::UNCERTAIN, $submission->getPart(1)?->getStatus());
        self::assertSame(FilingDeliveryStatus::NEEDS_REVIEW, $submission->getDeliveryStatus());
        self::assertTrue($submission->isActive(), 'The case stays held until the lawyer declares what happened.');
        self::assertSame(CaseStatus::CERERE_GENERATA, $this->case->getStatus());
    }

    public function testRetryEnvelopeOfACancelledSubmissionSendsNothing(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        $partId = (int) $submission->getPart(1)?->getId();
        $this->courtTransport()->failNext = RecordingCourtTransport::rcptRefused(451, 'Try again later');
        try {
            $this->handleNextFilingMessage();
        } catch (HandlerFailedException) {
        }

        static::getContainer()->get(FilingSubmissionService::class)->cancel($submission, $this->user);
        $this->handleFilingPart($partId);

        $this->em->refresh($submission);
        self::assertSame([], $this->courtTransport()->sent);
        self::assertSame(FilingDeliveryStatus::CANCELLED, $submission->getDeliveryStatus());
        self::assertSame(FilingPartStatus::CANCELLED, $submission->getPart(1)?->getStatus());
    }

    public function testRegistrationFromThisFilingStillSendsTheAnnexes(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 3);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleNextFilingMessage();

        $this->register('321/301/2026');

        $this->handleAllFilingMessages();
        $this->em->refresh($submission);
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());
        self::assertCount(3, $this->courtTransport()->sent, 'The court gets every annex its petition lists.');
        self::assertNull($this->em->getRepository(Notification::class)->findOneBy(['user' => $this->user, 'type' => NotificationType::FILING_ANNEXES_CANCELLED->value]));
    }

    public function testRegistrationOnAnotherChannelBeforeThePetitionLeftCancelsEverything(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 3);
        $submission = $this->submitFiling($this->case, $this->user);

        $this->register('321/301/2026');

        $this->em->refresh($submission);
        self::assertFalse($submission->isActive());
        self::assertSame(FilingDeliveryStatus::CANCELLED, $submission->getDeliveryStatus());
        foreach ([1, 2, 3] as $sequence) {
            self::assertSame(FilingPartStatus::CANCELLED, $submission->getPart($sequence)?->getStatus());
        }
        $this->handleAllFilingMessages();
        self::assertSame([], $this->courtTransport()->sent);
    }

    public function testRegistrationWhileThePetitionIsWaitingForARetryCancelsTheAnnexes(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 3);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->courtTransport()->failNext = RecordingCourtTransport::rcptRefused(451, 'Try again later');
        try {
            $this->handleNextFilingMessage();
        } catch (HandlerFailedException) {
        }
        $this->em->refresh($submission);
        self::assertSame(FilingPartStatus::RETRY_SCHEDULED, $submission->getPart(1)?->getStatus());

        $this->register('321/301/2026');

        $this->em->refresh($submission);
        self::assertFalse($submission->isActive());
        self::assertSame(FilingPartStatus::CANCELLED, $submission->getPart(2)?->getStatus());
        self::assertSame(FilingPartStatus::CANCELLED, $submission->getPart(3)?->getStatus());
    }

    public function testRegistrationDuringThePetitionHandOffDoesNotMoveTheCaseBack(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        static::getContainer()->get(DeadlineService::class)->createFilingDeadline($this->case, new \DateTimeImmutable('2026-02-03'));
        $submission = $this->submitFiling($this->case, $this->user);
        $caseId = (int) $this->case->getId();
        $submissionId = (int) $submission->getId();
        $conn = $this->em->getConnection();
        // Another request registers the case while the server is taking the petition:
        // the worker read the case before the hand-off.
        $this->courtTransport()->beforeSend = static function () use ($conn, $caseId, $submissionId): void {
            $conn->executeStatement("UPDATE legal_case SET status = 'DOSAR_INREGISTRAT', court_case_number = '321/301/2026' WHERE id = ?", [$caseId]);
            $conn->executeStatement("UPDATE filing_email_part SET status = 'CANCELLED' WHERE submission_id = ? AND sequence > 1", [$submissionId]);
            $conn->executeStatement("UPDATE filing_submission SET delivery_status = 'NEEDS_REVIEW', review_reason = ? WHERE id = ?", [FilingPartOutcomeApplier::REVIEW_ANNEXES_CANCELLED_PENDING, $submissionId]);
        };

        $this->handleNextFilingMessage();

        $this->em->clear();
        $case = $this->em->find(LegalCase::class, $caseId);
        self::assertSame(CaseStatus::DOSAR_INREGISTRAT, $case->getStatus());
        self::assertNull($case->getFilingChannel(), 'The stale case was not written back.');
        $deadline = static::getContainer()->get(LegalDeadlineRepository::class)->findOneByCaseAndType($case, DeadlineType::DEPUNERE_CERERE);
        self::assertFalse($deadline?->isCompleted(), 'DEPUNE_CERERE was not applied again.');
        $submission = $this->em->find(FilingSubmission::class, $submissionId);
        self::assertSame(FilingPartStatus::ACCEPTED, $submission->getPart(1)?->getStatus());
        self::assertSame(FilingDeliveryStatus::CLOSED_PARTIAL, $submission->getDeliveryStatus(), 'Closed by the pending registration review, not reopened as case_moved_on.');
        self::assertNull($this->em->getRepository(Notification::class)->findOneBy(['user' => $this->user->getId(), 'type' => NotificationType::FILING_NEEDS_REVIEW->value]));
    }

    public function testADeadlockWhileRecordingTheAcceptanceIsRetriedAndTheEmailEndsAccepted(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        $submissionId = (int) $submission->getId();
        $listener = new class {
            public bool $armed = false;
            public int $thrown = 0;

            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($this->armed) {
                    $this->armed = false;
                    ++$this->thrown;

                    throw new DeadlockException(new class('Deadlock found when trying to get lock') extends \Exception implements DriverException {
                        public function getSQLState(): ?string
                        {
                            return '40001';
                        }
                    }, null);
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::onFlush, $listener);
        $this->courtTransport()->beforeSend = static function () use ($listener): void {
            $listener->armed = true;
        };

        try {
            $this->handleNextFilingMessage();
        } finally {
            static::getContainer()->get(EntityManagerInterface::class)->getEventManager()->removeEventListener(Events::onFlush, $listener);
        }

        self::assertSame(1, $listener->thrown);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->clear();
        $submission = $this->em->find(FilingSubmission::class, $submissionId);
        self::assertSame(FilingPartStatus::ACCEPTED, $submission->getPart(1)?->getStatus());
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());
        self::assertSame(CaseStatus::CERERE_DEPUSA, $submission->getLegalCase()->getStatus());
        self::assertCount(1, $this->courtTransport()->sent, 'Recorded again, never sent again.');
    }

    public function testRegistrationWhileThePetitionIsBeingSentThenARefusalEndsAsFailed(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $submission = $this->submitFiling($this->case, $this->user);
        $caseId = (int) $this->case->getId();
        $submissionId = (int) $submission->getId();
        $conn = $this->em->getConnection();
        $this->courtTransport()->beforeSend = static function () use ($conn, $caseId, $submissionId): void {
            $conn->executeStatement("UPDATE legal_case SET status = 'DOSAR_INREGISTRAT', court_case_number = '321/301/2026' WHERE id = ?", [$caseId]);
            $conn->executeStatement("UPDATE filing_email_part SET status = 'CANCELLED' WHERE submission_id = ? AND sequence > 1", [$submissionId]);
            $conn->executeStatement("UPDATE filing_submission SET delivery_status = 'NEEDS_REVIEW', review_reason = ? WHERE id = ?", [FilingPartOutcomeApplier::REVIEW_ANNEXES_CANCELLED_PENDING, $submissionId]);
        };
        $this->courtTransport()->failNext = RecordingCourtTransport::endOfDataRefused(550, '5.7.1 Message rejected');

        try {
            $this->handleNextFilingMessage();
        } catch (HandlerFailedException) {
        }

        $this->em->clear();
        $submission = $this->em->find(FilingSubmission::class, $submissionId);
        self::assertSame(FilingPartStatus::FAILED_PERMANENT, $submission->getPart(1)?->getStatus());
        self::assertSame(FilingDeliveryStatus::FAILED_PERMANENT, $submission->getDeliveryStatus(), 'Nothing left, so not CLOSED_PARTIAL.');
        self::assertFalse($submission->isActive());
    }

    private function register(string $number): void
    {
        $this->actAs($this->user);
        $this->em->refresh($this->case);
        $this->case->setCourtCaseNumber($number);
        static::getContainer()->get(CaseWorkflowService::class)->apply($this->case, 'inregistreaza_dosar');
        $this->em->flush();
    }

    public function testManualFilingTransitionIsBlockedWhileASubmissionHoldsTheCase(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $this->submitFiling($this->case, $this->user);

        self::assertFalse(static::getContainer()->get(CaseWorkflowService::class)->can($this->case, 'depune_cerere'));
    }

    public function testCancelledSubmissionSendsNothing(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        static::getContainer()->get(FilingSubmissionService::class)->cancel($submission, $this->user);

        $this->handleAllFilingMessages();

        $this->em->refresh($submission);
        self::assertSame(FilingDeliveryStatus::CANCELLED, $submission->getDeliveryStatus());
        self::assertSame([], $this->courtTransport()->sent);
        self::assertFalse($submission->isActive());
    }
}
