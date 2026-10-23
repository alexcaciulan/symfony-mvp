<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Entity\CaseStatusHistory;
use App\Entity\CourtEmailAddressReport;
use App\Entity\FilingAttempt;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Enum\CourtEmailReportKind;
use App\Enum\CourtEmailStatus;
use App\Enum\DeadlineType;
use App\Enum\FilingAttemptOutcome;
use App\Enum\FilingAttemptSource;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingEvidenceStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartStatus;
use App\Enum\InboundClassification;
use App\Enum\InboundMatchConfidence;
use App\Enum\InboundMatchMethod;
use App\Enum\InboundReviewStatus;
use App\Enum\NotificationType;
use App\Repository\LegalDeadlineRepository;
use App\Service\Case\CaseWorkflowService;
use App\Service\Deadline\DeadlineService;
use App\Service\Filing\FilingDateService;
use App\Service\Filing\FilingPartOutcomeApplier;
use App\Service\Filing\FilingSendBlockedException;
use App\Service\Filing\FilingSendGuard;
use App\Service\Filing\FilingSubmissionService;
use App\Service\Filing\FilingTransitionPermit;
use App\Service\Filing\UncertainPartResolver;
use App\Service\Inbound\FilingInboundApplier;
use App\Service\Inbound\InboundForwarder;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use App\Tests\Support\InboundTestTrait;
use App\Tests\Support\RecordingCourtTransport;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Email;

/**
 * Each kind of received message against a submission the recording SMTP transport
 * accepted, through the ingestor and the processing handler as in production.
 */
final class FilingInboundApplierTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;
    use InboundTestTrait;

    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    /** @var list<User> */
    private array $otherUsers = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'inbound-apply');
        $this->courtTransport()->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->clear();
            foreach ($this->otherUsers as $other) {
                $this->removeFilingFixtures($this->em, $other, null);
            }
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testReceiptRecordsEvidenceOnlyAndForwardsToTheLawyer(): void
    {
        $submission = $this->sent();
        $mail = $this->receive($this->rawForPart($submission->getPart(1), 'justro-receipt'));

        self::assertSame(InboundClassification::RECEIPT, $mail->getClassification());
        self::assertSame(InboundMatchMethod::IN_REPLY_TO, $mail->getMatchMethod());
        self::assertSame(InboundMatchConfidence::CERTAIN, $mail->getMatchConfidence());
        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingEvidenceStatus::RECEIPT_RECEIVED, $submission->getEvidenceStatus());
        self::assertNotNull($submission->getCourtReceiptAt());
        self::assertSame($mail->getId(), $submission->getReceiptInboundMail()?->getId());
        $case = $submission->getLegalCase();
        self::assertNull($case->getFiledAt(), 'A receipt never sets the filing date.');
        self::assertNull($case->getCourtCaseNumber(), 'Nor the court case number.');
        self::assertSame(CaseStatus::CERERE_DEPUSA, $case->getStatus());

        $forwards = $this->forwards();
        self::assertCount(1, $forwards);
        self::assertSame([$this->user->getEmail()], array_map(static fn ($a) => $a->getAddress(), $forwards[0]->getTo()));
        self::assertSame('auto-forwarded', $forwards[0]->getHeaders()->get('Auto-Submitted')?->getBodyAsString());
        self::assertNotNull($mail->getForwardedAt());
        self::assertCount(1, $this->notices(NotificationType::FILING_RECEIPT_RECEIVED));
        self::assertTrue(static::getContainer()->get(FilingDateService::class)->canSet($case), 'The lawyer still declares the date.');
    }

    public function testReceiptIsNotForwardedWhenTheLawyerIsAlreadyInCopy(): void
    {
        $submission = $this->sent();
        $raw = $this->rawForPart($submission->getPart(1), 'justro-receipt');
        $raw = str_replace("To: depuneri@lexrecovery.test\n", "To: depuneri@lexrecovery.test\nCc: " . $this->user->getEmail() . "\n", $raw);

        $mail = $this->receive($raw);

        self::assertSame(FilingEvidenceStatus::RECEIPT_RECEIVED, $this->freshSubmission((int) $submission->getId())->getEvidenceStatus());
        self::assertNull($mail->getForwardedAt());
        self::assertSame([], $this->forwards());
    }

    public function testUnauthenticatedReceiptChangesNothingAndGoesToReview(): void
    {
        $submission = $this->sent();
        $mail = $this->receive($this->rawForPart($submission->getPart(1), 'receipt-forged-auth'));

        self::assertSame(InboundMatchConfidence::PROBABLE, $mail->getMatchConfidence());
        self::assertSame(InboundReviewStatus::PENDING, $mail->getReviewStatus());
        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingEvidenceStatus::AWAITING_RECEIPT, $submission->getEvidenceStatus());
        self::assertNull($submission->getCourtReceiptAt());
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());
        self::assertCount(1, $this->notices(NotificationType::FILING_INBOUND_REVIEW));
        self::assertSame([], $this->notices(NotificationType::FILING_RECEIPT_RECEIVED));
    }

    public function testOutOfOfficeSayingTheMessageWasReceivedIsNoReceipt(): void
    {
        $submission = $this->sent();
        $mail = $this->receive($this->rawForPart($submission->getPart(1), 'ro-autoreply-received'));

        self::assertSame(InboundClassification::AUTO_REPLY_OTHER, $mail->getClassification());
        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingEvidenceStatus::AWAITING_RECEIPT, $submission->getEvidenceStatus());
        self::assertNull($submission->getCourtReceiptAt());
        self::assertSame([], $this->notices(NotificationType::FILING_RECEIPT_RECEIVED));
    }

    public function testReadReceiptOnlyRecordsTheDisplayNotice(): void
    {
        $submission = $this->sent();
        $mail = $this->receive($this->rawForPart($submission->getPart(1), 'mdn'));

        self::assertSame(InboundClassification::READ_RECEIPT, $mail->getClassification());
        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertNotNull($submission->getReadReceiptAt());
        self::assertSame(FilingEvidenceStatus::AWAITING_RECEIPT, $submission->getEvidenceStatus());
        self::assertSame([], $this->forwards());
    }

    public function testPetitionBounceUndoesTheFiling(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        static::getContainer()->get(DeadlineService::class)->createFilingDeadline($this->case, new \DateTimeImmutable('2026-02-03'));
        $submission = $this->sentSubmission($this->case, $this->user);
        $caseId = (int) $submission->getLegalCase()->getId();
        self::assertTrue($this->filingDeadline($caseId)?->isCompleted(), 'Closed by the platform at CERERE_DEPUSA.');
        $statusNoticesBefore = count($this->notices(NotificationType::CASE_STATUS));

        $mail = $this->receive($this->rawForPart($submission->getPart(1), 'exchange-ndr-5.1.1'));

        self::assertSame(InboundClassification::BOUNCE_PERMANENT, $mail->getClassification());
        self::assertSame(InboundMatchMethod::VERP, $mail->getMatchMethod());
        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::UNDELIVERED, $submission->getDeliveryStatus());
        self::assertFalse($submission->isActive());
        $part = $submission->getPart(1);
        self::assertSame(FilingPartStatus::FAILED_PERMANENT, $part?->getStatus());
        self::assertSame('5.1.1', $part->getLastEnhancedStatus());
        self::assertSame(FilingAttemptSource::ASYNC_BOUNCE, $part->latestAttempt()?->getSource());
        self::assertSame(FilingPackageStatus::SIGNED, $submission->getPackage()->getStatus());

        // A delivery report never takes the shared address out of use: it goes to the admin.
        $address = $submission->getCourtEmailAddress();
        self::assertSame(CourtEmailStatus::VALIDATED, $address?->getStatus());
        $reports = $this->em->getRepository(CourtEmailAddressReport::class)->findBy(['address' => $address->getId()]);
        self::assertCount(1, $reports);
        self::assertSame(CourtEmailReportKind::DELIVERY_REPORT, $reports[0]->getKind());
        self::assertTrue($reports[0]->isOpen());
        self::assertSame($mail->getId(), $reports[0]->getInboundMail()?->getId());
        self::assertSame('5.1.1', $reports[0]->getEnhancedStatus());

        $case = $submission->getLegalCase();
        self::assertSame(CaseStatus::CERERE_GENERATA, $case->getStatus());
        self::assertNull($case->getFilingChannel());
        self::assertNull($case->getFilingReference());
        self::assertFalse($this->filingDeadline($caseId)?->isCompleted(), 'The platform\'s own closing is reopened.');

        $history = $this->em->getRepository(CaseStatusHistory::class)->findOneBy(['legalCase' => $caseId, 'newStatus' => CaseStatus::CERERE_GENERATA->value, 'oldStatus' => CaseStatus::CERERE_DEPUSA->value]);
        self::assertNotNull($history);
        self::assertNull($history->getCreatedBy(), 'Applied by the system.');

        $notices = $this->notices(NotificationType::FILING_BOUNCED_PERMANENT);
        self::assertCount(1, $notices);
        self::assertStringContainsString('NCC art. 2540', $notices[0]->getMessage());
        self::assertStringNotContainsString('186', $notices[0]->getMessage());
        self::assertStringNotContainsString('181', $notices[0]->getMessage());
        self::assertStringContainsString('raportată administratorului', $notices[0]->getMessage());
        self::assertCount($statusNoticesBefore, $this->notices(NotificationType::CASE_STATUS), 'No cerere_generata or cerere_depusa notice.');
        self::assertFalse(static::getContainer()->get(FilingDateService::class)->canSet($case));

        // The same failure reported again changes nothing.
        $attempts = $part->getAttempts()->count();
        $this->receive($this->rawForPart($submission->getPart(1), 'postfix-5.1.1'));
        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame($attempts, $submission->getPart(1)?->getAttempts()->count());
        self::assertSame(CaseStatus::CERERE_GENERATA, $submission->getLegalCase()->getStatus());
        self::assertCount(1, $this->notices(NotificationType::FILING_BOUNCED_PERMANENT));

        // Sending again from this case waits for the admin; another lawyer's case at the court does not.
        $this->actAs($this->user);
        $case = $this->em->getRepository(LegalCase::class)->find($caseId);
        self::assertContains('filing.send.blocker.address_bounce_reported', static::getContainer()->get(FilingSendGuard::class)->checkForSubmit($case, $case->getUser())->blockers);
        $this->countyFixtureCache = [];
        $other = $this->createFilingUser($this->em, 'inbound-apply-other');
        $this->otherUsers[] = $other;
        $otherCase = $this->createFilingCase($this->em, $other);
        $otherCase->setCourt($this->em->getRepository(LegalCase::class)->find($caseId)->getCourt());
        $this->em->flush();
        $this->actAs($other);
        self::assertNotContains('filing.send.blocker.address_bounce_reported', static::getContainer()->get(FilingSendGuard::class)->checkForSubmit($otherCase, $other)->blockers);
    }

    public function testLatePetitionBounceWhileAnnexesAreOnTheirWayHoldsTheCaseForAReviewThatFreesIt(): void
    {
        $submission = $this->annexesOnTheirWay();

        $this->receive($this->rawForPart($submission->getPart(1), 'exchange-ndr-5.1.1'));

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::NEEDS_REVIEW, $submission->getDeliveryStatus());
        self::assertSame(FilingPartOutcomeApplier::REVIEW_LATE_BOUNCE, $submission->getReviewReason());
        self::assertTrue($submission->isActive());
        self::assertSame(FilingPartStatus::CANCELLED, $submission->getPart(3)?->getStatus(), 'The emails still waiting are not sent after a late bounce.');
        self::assertSame(CaseStatus::CERERE_DEPUSA, $submission->getLegalCase()->getStatus());
        $service = static::getContainer()->get(FilingSubmissionService::class);
        self::assertTrue($service->isLateBounceReview($submission));
        self::assertFalse($service->isCancellable($submission), 'The petition left: it is never "nothing was sent".');

        $this->actAs($this->user);
        $service->acknowledgeLateBounce($submission, $this->em->getRepository(User::class)->find($this->user->getId()));

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertFalse($submission->isActive(), 'The acknowledgement frees the case.');
        self::assertSame(FilingDeliveryStatus::CLOSED_PARTIAL, $submission->getDeliveryStatus());
        self::assertNotNull($submission->getCancelledAt());
    }

    public function testAnAdminCanEndTheLateBounceHoldOnAnActiveSubmission(): void
    {
        $submission = $this->annexesOnTheirWay();
        $this->receive($this->rawForPart($submission->getPart(1), 'exchange-ndr-5.1.1'));
        $admin = $this->createFilingUser($this->em, 'inbound-apply-admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->otherUsers[] = $admin;

        static::getContainer()->get(FilingSubmissionService::class)->closeByAdmin($this->freshSubmission((int) $submission->getId()), $this->em->getRepository(User::class)->find($admin->getId()), 'Verificat cu avocatul la telefon');

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertFalse($submission->isActive());
        self::assertSame(FilingDeliveryStatus::CLOSED_PARTIAL, $submission->getDeliveryStatus());
        self::assertSame(CaseStatus::CERERE_DEPUSA, $submission->getLegalCase()->getStatus());
    }

    public function testALatePetitionBounceWithNoAnnexAcceptedNeverMakesTheSubmissionCancellable(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleNextFilingMessage();
        $this->leaveTheRestWaiting($submission, [2]);
        $case = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        $case->setFiledAt(new \DateTimeImmutable('today'));
        $this->em->flush();

        $this->receive($this->rawForPart($this->freshSubmission((int) $submission->getId())->getPart(1), 'exchange-ndr-5.1.1'));

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingPartStatus::FAILED_PERMANENT, $submission->getPart(1)?->getStatus());
        self::assertSame(FilingPartStatus::CANCELLED, $submission->getPart(2)?->getStatus());
        self::assertFalse(static::getContainer()->get(FilingSubmissionService::class)->isCancellable($submission), 'The petition left even though no annex did.');
        self::assertTrue(static::getContainer()->get(FilingSubmissionService::class)->isLateBounceReview($submission));
    }

    public function testABounceSettlesAnUncertainPetitionAsReturned(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->courtTransport()->failNext = RecordingCourtTransport::lostAfterData();
        try {
            $this->handleNextFilingMessage();
        } catch (HandlerFailedException) {
            // The connection was lost after DATA: the outcome is unknown.
        }
        self::assertSame(FilingPartStatus::UNCERTAIN, $this->freshSubmission((int) $submission->getId())->getPart(1)?->getStatus());

        $mail = $this->receive($this->rawForPart($this->freshSubmission((int) $submission->getId())->getPart(1), 'exchange-ndr-5.1.1'));

        $submission = $this->freshSubmission((int) $submission->getId());
        $part = $submission->getPart(1);
        self::assertSame(FilingPartStatus::FAILED_PERMANENT, $part?->getStatus());
        self::assertSame(FilingAttemptSource::ASYNC_BOUNCE, $part->latestAttempt()?->getSource());
        self::assertSame(FilingDeliveryStatus::FAILED_PERMANENT, $submission->getDeliveryStatus());
        self::assertFalse($submission->isActive());
        self::assertSame(CaseStatus::CERERE_GENERATA, $submission->getLegalCase()->getStatus());
        self::assertNotSame(InboundReviewStatus::PENDING, $mail->getReviewStatus());
        self::assertSame(CourtEmailStatus::VALIDATED, $submission->getCourtEmailAddress()?->getStatus());

        // The CC copy can no longer be declared received over the report.
        $this->expectException(FilingSendBlockedException::class);
        static::getContainer()->get(UncertainPartResolver::class)->declareReceived($part, $this->em->getRepository(User::class)->find($this->user->getId()), [UncertainPartResolver::CONFIRM_RECEIVED => true]);
    }

    public function testABounceOnAPartStillBeingSentGoesToReviewAndChangesNothing(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        $entity = $this->em->getRepository(FilingSubmission::class)->find($submission->getId());
        $entity->getPart(1)?->setStatus(FilingPartStatus::SENDING);
        $this->em->flush();

        $mail = $this->receive($this->rawForPart($entity->getPart(1), 'exchange-ndr-5.1.1'));

        self::assertSame(InboundReviewStatus::PENDING, $mail->getReviewStatus());
        self::assertSame(FilingInboundApplier::REVIEW_PART_NOT_ACCEPTED, $mail->getReviewReason());
        self::assertSame(FilingPartStatus::SENDING, $this->freshSubmission((int) $submission->getId())->getPart(1)?->getStatus());
        self::assertCount(1, $this->notices(NotificationType::FILING_INBOUND_REVIEW));
    }

    public function testAnAnnexBounceAfterAnAcknowledgedLateBounceIsToldAndNotReadAsAPetitionFailure(): void
    {
        $submission = $this->sent(2);
        $case = $this->em->getRepository(LegalCase::class)->find($submission->getLegalCase()->getId());
        $case->setFiledAt(new \DateTimeImmutable('today'));
        $this->em->flush();
        $this->receive($this->rawForPart($submission->getPart(1), 'exchange-ndr-5.1.1'));
        $this->actAs($this->user);
        static::getContainer()->get(FilingSubmissionService::class)->acknowledgeLateBounce($this->freshSubmission((int) $submission->getId()), $this->em->getRepository(User::class)->find($this->user->getId()));

        $mail = $this->receive($this->rawForPart($this->freshSubmission((int) $submission->getId())->getPart(2), 'postfix-5.1.1'));

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus(), 'Not relabelled as a failed petition.');
        self::assertFalse($submission->isActive());
        self::assertSame(FilingPartStatus::FAILED_PERMANENT, $submission->getPart(2)?->getStatus());
        self::assertSame('annex_bounce', $mail->getReviewReason());
        $annexNotices = array_filter($this->notices(NotificationType::FILING_BOUNCED_PERMANENT), static fn (Notification $n): bool => str_contains((string) $n->getDedupKey(), 'annex_bounced'));
        self::assertCount(1, $annexNotices);
    }

    /** @return iterable<string, array{string}> */
    public static function receiptEvidence(): iterable
    {
        yield 'automatic receipt' => ['receipt'];
        yield 'uploaded receipt' => ['manual'];
    }

    #[DataProvider('receiptEvidence')]
    public function testABounceAfterAReceiptDoesNotUndoTheFiling(string $evidence): void
    {
        $submission = $this->sent();
        if ($evidence === 'receipt') {
            $this->receive($this->rawForPart($submission->getPart(1), 'justro-receipt'));
        } else {
            $entity = $this->em->getRepository(FilingSubmission::class)->find($submission->getId());
            $entity->markReceipt(null, null, FilingEvidenceStatus::RECEIPT_MANUAL);
            $this->em->flush();
        }

        $mail = $this->receive($this->rawForPart($this->freshSubmission((int) $submission->getId())->getPart(1), 'exchange-ndr-5.1.1'));

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(CaseStatus::CERERE_DEPUSA, $submission->getLegalCase()->getStatus(), 'The court mailbox answered: a later report does not undo the filing.');
        self::assertSame(FilingDeliveryStatus::NEEDS_REVIEW, $submission->getDeliveryStatus());
        self::assertSame(FilingPartOutcomeApplier::REVIEW_LATE_BOUNCE, $submission->getReviewReason());
        self::assertSame(InboundReviewStatus::PENDING, $mail->getReviewStatus());
        self::assertSame([], $this->notices(NotificationType::FILING_BOUNCED_PERMANENT));
    }

    public function testPetitionThenAnnexReportsGiveOneNoticeForAnUndoneFiling(): void
    {
        $submission = $this->sent(2);

        $this->receive($this->rawForPart($submission->getPart(1), 'exchange-ndr-5.1.1'));
        $annex = $this->receive($this->rawForPart($this->freshSubmission((int) $submission->getId())->getPart(2), 'postfix-5.1.1'));

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::UNDELIVERED, $submission->getDeliveryStatus());
        self::assertSame(FilingPartStatus::FAILED_PERMANENT, $submission->getPart(2)?->getStatus(), 'Recorded as evidence.');
        self::assertNotSame(InboundReviewStatus::PENDING, $annex->getReviewStatus());
        self::assertCount(1, $this->notices(NotificationType::FILING_BOUNCED_PERMANENT), 'No annex notice on a filing that was undone.');
        self::assertSame([], $this->notices(NotificationType::FILING_PARTIALLY_SENT));
    }

    public function testAnnexThenPetitionReportsLeaveOnlyTheUndoNoticeActive(): void
    {
        $submission = $this->sent(2);

        $this->receive($this->rawForPart($submission->getPart(2), 'postfix-5.1.1'));
        self::assertCount(1, $this->notices(NotificationType::FILING_PARTIALLY_SENT));
        $this->receive($this->rawForPart($this->freshSubmission((int) $submission->getId())->getPart(1), 'exchange-ndr-5.1.1'));

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::UNDELIVERED, $submission->getDeliveryStatus());
        self::assertSame(CaseStatus::CERERE_GENERATA, $submission->getLegalCase()->getStatus());
        $unread = array_filter(
            [...$this->notices(NotificationType::FILING_PARTIALLY_SENT), ...$this->notices(NotificationType::FILING_BOUNCED_PERMANENT)],
            static fn (Notification $n): bool => !$n->isRead(),
        );
        self::assertCount(1, $unread);
        self::assertSame(NotificationType::FILING_BOUNCED_PERMANENT->value, array_values($unread)[0]->getType());
    }

    public function testPetitionBounceOnACaseThatMovedOnOnlyOpensAReview(): void
    {
        $submission = $this->sent();
        $case = $this->em->getRepository(LegalCase::class)->find($submission->getLegalCase()->getId());
        $case->setFiledAt(new \DateTimeImmutable('today'));
        $this->em->flush();

        $mail = $this->receive($this->rawForPart($submission->getPart(1), 'exchange-ndr-5.1.1'));

        self::assertSame(InboundReviewStatus::PENDING, $mail->getReviewStatus());
        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::NEEDS_REVIEW, $submission->getDeliveryStatus());
        self::assertTrue($submission->isActive(), 'The case is held until the lawyer checks.');
        self::assertSame(CaseStatus::CERERE_DEPUSA, $submission->getLegalCase()->getStatus());
        self::assertSame(CourtEmailStatus::VALIDATED, $submission->getCourtEmailAddress()?->getStatus());
        self::assertCount(1, $this->notices(NotificationType::FILING_NEEDS_REVIEW));
        self::assertFalse(static::getContainer()->get(FilingSubmissionService::class)->isCancellable($submission));

        $this->actAs($this->user);
        static::getContainer()->get(FilingSubmissionService::class)->acknowledgeLateBounce($submission, $this->em->getRepository(User::class)->find($this->user->getId()));

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());
        self::assertFalse($submission->isActive());
        self::assertSame(InboundReviewStatus::RESOLVED, $this->reloadInbound((int) $mail->getId())->getReviewStatus());
    }

    /** @return iterable<string, array{string, string, string, FilingPartStatus}> */
    public static function nonAddressBounces(): iterable
    {
        yield 'full mailbox' => ['5.1.1', '5.2.2', '552 5.2.2 Mailbox full', FilingPartStatus::FAILED_PERMANENT];
        yield 'size' => ['5.1.1', '5.3.4', '552 5.3.4 Message size exceeds fixed maximum', FilingPartStatus::FAILED_SIZE];
    }

    #[DataProvider('nonAddressBounces')]
    public function testPetitionBounceForMailboxOrSizeUndoesWithoutInvalidatingTheAddress(string $from, string $status, string $diagnostic, FilingPartStatus $partStatus): void
    {
        $submission = $this->sent();
        $raw = $this->rawForPart($submission->getPart(1), 'exchange-ndr-5.1.1');
        $raw = str_replace(['Status: ' . $from, 'smtp; 550 5.1.1 RESOLVER.ADR.RecipNotFound; not found'], ['Status: ' . $status, 'smtp; ' . $diagnostic], $raw);

        $this->receive($raw);

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::UNDELIVERED, $submission->getDeliveryStatus());
        self::assertSame($partStatus, $submission->getPart(1)?->getStatus());
        self::assertSame(CaseStatus::CERERE_GENERATA, $submission->getLegalCase()->getStatus());
        self::assertSame(CourtEmailStatus::VALIDATED, $submission->getCourtEmailAddress()?->getStatus());
    }

    public function testAnnexBounceOnASentSubmissionReopensItAsPartiallySent(): void
    {
        $submission = $this->sent(2);
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());

        $this->receive($this->rawForPart($submission->getPart(2), 'postfix-5.1.1'));

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::PARTIALLY_SENT, $submission->getDeliveryStatus());
        self::assertTrue($submission->isActive());
        self::assertSame(FilingPartStatus::ACCEPTED, $submission->getPart(1)?->getStatus());
        self::assertSame(FilingPartStatus::FAILED_PERMANENT, $submission->getPart(2)?->getStatus());
        self::assertSame(CourtEmailStatus::VALIDATED, $submission->getCourtEmailAddress()?->getStatus(), 'An annex never invalidates the address.');
        self::assertSame(CaseStatus::CERERE_DEPUSA, $submission->getLegalCase()->getStatus());
        self::assertCount(1, $this->notices(NotificationType::FILING_PARTIALLY_SENT));
    }

    public function testAnnexBounceAfterTheRestWasClosedOnlyGoesToReview(): void
    {
        $submission = $this->sent(2);
        $entity = $this->em->getRepository(FilingSubmission::class)->find($submission->getId());
        $entity->setDeliveryStatus(FilingDeliveryStatus::CLOSED_PARTIAL);
        $this->em->flush();

        $mail = $this->receive($this->rawForPart($submission->getPart(2), 'postfix-5.1.1'));

        self::assertSame(InboundReviewStatus::PENDING, $mail->getReviewStatus());
        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::CLOSED_PARTIAL, $submission->getDeliveryStatus());
        self::assertFalse($submission->isActive());
        self::assertSame(FilingPartStatus::FAILED_PERMANENT, $submission->getPart(2)?->getStatus());
        self::assertCount(1, $this->notices(NotificationType::FILING_BOUNCED_PERMANENT));
    }

    public function testTemporaryAndDelayedReportsOnlyRecordAndNotify(): void
    {
        $submission = $this->sent();
        $sentBefore = count($this->courtTransport()->sent);

        $this->receive($this->rawForPart($submission->getPart(1), 'temp-4.4.7'));
        $this->receive($this->rawForPart($submission->getPart(1), 'delayed'));

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());
        self::assertSame(FilingPartStatus::ACCEPTED, $submission->getPart(1)?->getStatus());
        $async = array_values(array_filter($submission->getPart(1)->getAttempts()->toArray(), static fn (FilingAttempt $a): bool => $a->getSource() === FilingAttemptSource::ASYNC_BOUNCE));
        self::assertCount(2, $async);
        self::assertSame([FilingAttemptOutcome::TEMPORARY, FilingAttemptOutcome::TEMPORARY], array_map(static fn (FilingAttempt $a) => $a->getOutcome(), $async));
        self::assertCount(1, $this->notices(NotificationType::FILING_DELIVERY_DELAYED), 'One notice per email.');
        self::assertCount($sentBefore, $this->courtTransport()->sent, 'Nothing is resent on a report.');
        self::assertSame([], [...$this->courtQueue()->get()]);
    }

    public function testBounceOfTheLawyersCopyLeavesTheSubmissionAlone(): void
    {
        $submission = $this->sent();

        $this->receive($this->rawForPart($submission->getPart(1), 'exchange-ndr-5.1.1', ['{{COURT}}' => $submission->getCcAddress()]));

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());
        self::assertSame(FilingPartStatus::ACCEPTED, $submission->getPart(1)?->getStatus());
        self::assertSame(CourtEmailStatus::VALIDATED, $submission->getCourtEmailAddress()?->getStatus());
        self::assertCount(1, $this->notices(NotificationType::FILING_CC_BOUNCED));
    }

    public function testCourtReplyIsForwardedToTheLawyerOnlyWithTheProceduralAddressWarning(): void
    {
        $submission = $this->sent();

        $mail = $this->receive($this->rawForPart($submission->getPart(1), 'human-reply'));

        self::assertSame(InboundClassification::HUMAN_REPLY, $mail->getClassification());
        $forwards = $this->forwards();
        self::assertCount(1, $forwards);
        self::assertSame([$this->user->getEmail()], array_map(static fn ($a) => $a->getAddress(), $forwards[0]->getTo()));
        self::assertSame([], $forwards[0]->getCc());
        self::assertStringNotContainsString('<script>', (string) $forwards[0]->getHtmlBody());
        $notices = $this->notices(NotificationType::FILING_COURT_MESSAGE);
        self::assertCount(1, $notices);
        self::assertStringContainsString('nu este adresă procedurală', $notices[0]->getMessage());
        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());
        self::assertSame(FilingEvidenceStatus::AWAITING_RECEIPT, $submission->getEvidenceStatus());
    }

    public function testUndoTransitionNeedsThePlatformPermitAndACaseThatDidNotMoveOn(): void
    {
        $submission = $this->sent();
        $case = $this->em->getRepository(LegalCase::class)->find($submission->getLegalCase()->getId());
        $workflow = static::getContainer()->get(CaseWorkflowService::class);
        $permit = static::getContainer()->get(FilingTransitionPermit::class);
        $t9 = CaseTransition::ANULEAZA_DEPUNERE_NELIVRATA->value;

        self::assertFalse($workflow->can($case, $t9), 'Not without the platform.');
        $permit->grant((int) $submission->getId());
        self::assertTrue($workflow->can($case, $t9));
        $case->setCourtCaseNumber('1234/299/2026');
        self::assertFalse($workflow->can($case, $t9), 'Not once the case has a court number.');
        $case->setCourtCaseNumber(null);
        $case->setFiledAt(new \DateTimeImmutable('today'));
        self::assertFalse($workflow->can($case, $t9), 'Not once the lawyer declared a filing date.');
        $permit->revoke();
    }

    public function testLawyerClosedFilingDeadlineIsNotReopened(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $deadlines = static::getContainer()->get(DeadlineService::class);
        $deadline = $deadlines->createFilingDeadline($this->case, new \DateTimeImmutable('2026-02-03'));
        self::assertNotNull($deadline);
        $deadline->markCompleted($this->user);
        $this->em->flush();

        self::assertFalse($deadlines->reopenFilingDeadline($this->case, 'test'));
        self::assertTrue($this->filingDeadline((int) $this->case->getId())?->isCompleted());

        $deadline = $this->filingDeadline((int) $this->case->getId());
        $deadline->setCompleted(false);
        $deadline->markCompleted(null);
        $this->em->flush();
        self::assertTrue($deadlines->reopenFilingDeadline($this->case, 'test'));
        self::assertFalse($this->filingDeadline((int) $this->case->getId())?->isCompleted());
    }

    /**
     * Three emails: the petition and the first annex accepted, the last annex
     * waiting for a retry, and the filing date already declared.
     */
    private function annexesOnTheirWay(): FilingSubmission
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 3);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleNextFilingMessage();
        $this->handleNextFilingMessage();
        $this->leaveTheRestWaiting($submission, [3]);
        $case = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        $case->setFiledAt(new \DateTimeImmutable('today'));
        $this->em->flush();

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertTrue($submission->isActive());
        self::assertSame(FilingPartStatus::ACCEPTED, $submission->getPart(2)?->getStatus());

        return $submission;
    }

    /** @param list<int> $sequences parts left waiting for a retry instead of being sent */
    private function leaveTheRestWaiting(FilingSubmission $submission, array $sequences): void
    {
        foreach ([...$this->courtQueue()->get()] as $envelope) {
            $this->courtQueue()->ack($envelope);
        }
        $this->em->clear();
        $entity = $this->em->getRepository(FilingSubmission::class)->find($submission->getId());
        foreach ($sequences as $sequence) {
            $entity->getPart($sequence)?->setStatus(FilingPartStatus::RETRY_SCHEDULED);
        }
        $this->em->flush();
    }

    private function sent(int $emails = 1): FilingSubmission
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, $emails);

        return $this->sentSubmission($this->case, $this->user);
    }

    private function filingDeadline(int $caseId): ?LegalDeadline
    {
        $this->em->clear();

        return static::getContainer()->get(LegalDeadlineRepository::class)->findOneByCaseAndType($this->em->getRepository(LegalCase::class)->find($caseId), DeadlineType::DEPUNERE_CERERE);
    }

    /** @return list<Email> */
    private function forwards(): array
    {
        $sent = array_filter(self::getMailerEvents(), static fn ($e): bool => !$e->isQueued());

        return array_values(array_filter(
            array_map(static fn ($e) => $e->getMessage(), $sent),
            static fn ($m): bool => $m instanceof Email && $m->getHeaders()->has(InboundForwarder::LOOP_HEADER),
        ));
    }

    /** @return list<Notification> */
    private function notices(NotificationType $type): array
    {
        return $this->em->getRepository(Notification::class)->findBy(['user' => $this->user->getId(), 'type' => $type->value], ['id' => 'ASC']);
    }
}
