<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\AuditLog;
use App\Entity\CaseStatusHistory;
use App\Entity\FilingAttempt;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\FilingAttemptOutcome;
use App\Enum\FilingAttemptSource;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartStatus;
use App\Service\Case\CaseWorkflowService;
use App\Service\Filing\FilingPartOutcomeApplier;
use App\Service\Filing\FilingResumeService;
use App\Service\Filing\FilingSendBlockedException;
use App\Service\Filing\FilingSubmissionService;
use App\Service\Filing\UncertainPartResolver;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use App\Tests\Support\RecordingCourtTransport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * The ways out of a stopped submission: the lawyer's declaration about the copy
 * in CC, the admin's operational override, closing the rest, and the registration
 * of the case on another path.
 */
final class UncertainPartResolverTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;

    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private ?User $admin = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('app.uploads_dir');
        $this->user = $this->createFilingUser($this->em, 'filing-uncertain');
        $this->courtTransport()->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
            if ($this->admin !== null) {
                $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$this->admin->getId()]);
                $this->removeFilingFixtures($this->em, $this->admin, null);
            }
        }
        parent::tearDown();
    }

    public function testReceivedCopyOfThePetitionAcceptsItAndContinues(): void
    {
        $submission = $this->uncertainAt(1, 2);

        $this->resolver()->declareReceived($submission->getPart(1) ?? throw new \LogicException(), $this->user, [UncertainPartResolver::CONFIRM_RECEIVED => true]);

        $this->em->refresh($submission);
        self::assertSame(FilingPartStatus::ACCEPTED, $submission->getPart(1)?->getStatus());
        self::assertSame(FilingDeliveryStatus::PARTIALLY_SENT, $submission->getDeliveryStatus());
        self::assertNull($submission->getReviewReason());
        self::assertSame(CaseStatus::CERERE_DEPUSA, $this->case->getStatus());
        self::assertNull($this->case->getFiledAt(), 'The filing date stays the lawyer\'s own declaration.');
        $history = $this->em->getRepository(CaseStatusHistory::class)->findOneBy(['legalCase' => $this->case, 'newStatus' => CaseStatus::CERERE_DEPUSA->value]);
        self::assertSame($this->user->getId(), $history?->getCreatedBy()?->getId());

        $attempts = $this->em->getRepository(FilingAttempt::class)->findBy(['part' => $submission->getPart(1)], ['attemptNo' => 'ASC']);
        $last = end($attempts);
        self::assertSame(FilingAttemptSource::LAWYER_CC_CHECK, $last->getSource());
        self::assertSame(FilingAttemptOutcome::ACCEPTED, $last->getOutcome());
        $confirmation = array_values(array_filter($submission->getConfirmations(), static fn (array $c): bool => $c['key'] === 'uncertain_p1:' . UncertainPartResolver::CONFIRM_RECEIVED));
        self::assertCount(1, $confirmation);
        self::assertStringContainsString('data aproximată', $confirmation[0]['text'], 'The stored declaration names its consequence for the petition.');
        $audit = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'filing_part_uncertain_resolved', 'user' => $this->user]);
        self::assertTrue($audit?->getNewData()['sentAtApproximated'] ?? null);
        self::assertSame($submission->getPart(1)?->getSentAt()?->format(\DateTimeInterface::ATOM), $audit?->getNewData()['sentAt'] ?? null);

        $queued = [...$this->courtQueue()->get()];
        self::assertCount(1, $queued);
        self::assertSame($submission->getPart(2)?->getId(), $queued[0]->getMessage()->partId);
    }

    public function testReceivedCopyOfAnAnnexKeepsThePlainDeclaration(): void
    {
        $submission = $this->uncertainAt(2, 2);

        $this->resolver()->declareReceived($submission->getPart(2) ?? throw new \LogicException(), $this->user, [UncertainPartResolver::CONFIRM_RECEIVED => true]);

        $this->em->refresh($submission);
        self::assertSame(FilingPartStatus::ACCEPTED, $submission->getPart(2)?->getStatus());
        $texts = array_column(array_filter($submission->getConfirmations(), static fn (array $c): bool => $c['key'] === 'uncertain_p2:' . UncertainPartResolver::CONFIRM_RECEIVED), 'text');
        self::assertSame(['Declar că am primit copia în CC a acestui mesaj.'], array_values($texts));
        $audit = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'filing_part_uncertain_resolved', 'user' => $this->user]);
        self::assertNotNull($audit);
        self::assertArrayNotHasKey('sentAtApproximated', $audit->getNewData());
    }

    public function testDeclarationNeedsTheTickedStatement(): void
    {
        $submission = $this->uncertainAt(1, 1);

        $this->expectException(FilingSendBlockedException::class);
        $this->resolver()->declareReceived($submission->getPart(1) ?? throw new \LogicException(), $this->user, []);
    }

    public function testCopyOfThePetitionNotReceivedEndsTheSubmission(): void
    {
        $submission = $this->uncertainAt(1, 1);

        $this->resolver()->declareNotReceived($submission->getPart(1) ?? throw new \LogicException(), $this->user, [UncertainPartResolver::CONFIRM_NOT_RECEIVED => true]);

        $this->em->refresh($submission);
        self::assertSame(FilingPartStatus::FAILED_INTERNAL, $submission->getPart(1)?->getStatus());
        self::assertSame(FilingDeliveryStatus::FAILED_INTERNAL, $submission->getDeliveryStatus());
        self::assertFalse($submission->isActive());
        self::assertSame(FilingPackageStatus::SIGNED, $submission->getPackage()->getStatus());
        self::assertSame(CaseStatus::CERERE_GENERATA, $this->case->getStatus());
    }

    public function testCopyOfAnAnnexNotReceivedLeavesItResumable(): void
    {
        $submission = $this->uncertainAt(2, 2);

        $this->resolver()->declareNotReceived($submission->getPart(2) ?? throw new \LogicException(), $this->user, [UncertainPartResolver::CONFIRM_NOT_RECEIVED => true]);

        $this->em->refresh($submission);
        self::assertSame(FilingDeliveryStatus::PARTIALLY_SENT, $submission->getDeliveryStatus());
        self::assertTrue($submission->isStalled());
        self::assertTrue(static::getContainer()->get(FilingResumeService::class)->isResumable($submission));
    }

    public function testAdminOverrideNeedsAReasonAndNeverDeclaresThePetitionAccepted(): void
    {
        $submission = $this->uncertainAt(1, 1);
        $part = $submission->getPart(1) ?? throw new \LogicException();
        $admin = $this->admin();

        foreach ([['failed', ''], ['accepted', 'Verificat telefonic la registratură']] as [$outcome, $reason]) {
            try {
                $this->resolver()->adminOverride($part, $admin, $outcome, $reason);
                self::fail('The override must be refused.');
            } catch (FilingSendBlockedException $e) {
                self::assertContains($e->messageKey, ['filing.override.error.reason_required', 'filing.override.error.petition_lawyer_only']);
            }
        }
        $this->em->refresh($part);
        self::assertSame(FilingPartStatus::UNCERTAIN, $part->getStatus());

        $this->resolver()->adminOverride($part, $admin, UncertainPartResolver::OVERRIDE_FAILED, 'Avocatul a confirmat telefonic că nu a primit copia');

        $this->em->refresh($submission);
        self::assertSame(FilingPartStatus::FAILED_INTERNAL, $submission->getPart(1)?->getStatus());
        self::assertFalse($submission->isActive());
        self::assertNull($this->case->getFiledAt());
        $audit = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'filing_part_admin_override', 'user' => $admin]);
        self::assertNotNull($audit);
        self::assertSame('UNCERTAIN', $audit->getNewData()['old']['part'] ?? null);
        self::assertSame('FAILED_INTERNAL', $audit->getNewData()['new']['part'] ?? null);
        self::assertStringContainsString('nu a primit', (string) ($audit->getNewData()['reason'] ?? ''));
    }

    public function testCloseRemainingFreesTheCaseAndKeepsTheEvidence(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 3);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleNextFilingMessage();
        $this->failNext(RecordingCourtTransport::rcptRefused(554, 'Transaction failed'));
        $service = static::getContainer()->get(FilingSubmissionService::class);
        $this->em->refresh($submission);
        self::assertTrue($service->isClosable($submission));

        try {
            $service->closeRemaining($submission, $this->user, []);
            self::fail('Closing needs the statement about the missing annexes.');
        } catch (FilingSendBlockedException $e) {
            self::assertSame('filing.send.error.confirmation_required', $e->messageKey);
        }
        $service->closeRemaining($submission, $this->user, [FilingSubmissionService::CONFIRM_CLOSE_INCOMPLETE => true]);

        $this->em->refresh($submission);
        self::assertSame(FilingDeliveryStatus::CLOSED_PARTIAL, $submission->getDeliveryStatus());
        self::assertNull($submission->getActiveLock());
        self::assertSame(FilingPartStatus::FAILED_PERMANENT, $submission->getPart(2)?->getStatus(), 'The failure stays as evidence.');
        self::assertSame(FilingPartStatus::CANCELLED, $submission->getPart(3)?->getStatus());
        self::assertFileExists($this->filingUploadsDir . '/' . $submission->getPart(1)?->getEmlPath());
        self::assertFileExists($this->filingUploadsDir . '/' . $submission->getPart(2)?->getEmlPath());
        self::assertCount(1, $this->em->getRepository(FilingAttempt::class)->findBy(['part' => $submission->getPart(2)]));
    }

    public function testRegistrationThatFollowsThisFilingKeepsSendingTheWaitingAnnex(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleNextFilingMessage();

        $this->register();

        $this->em->refresh($submission);
        self::assertTrue($submission->isActive(), 'The petition listing the annexes came from this filing: they still go.');
        self::assertSame(FilingPartStatus::PENDING, $submission->getPart(2)?->getStatus());

        $this->handleAllFilingMessages();

        $this->em->refresh($submission);
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());
        self::assertSame(FilingPartStatus::ACCEPTED, $submission->getPart(2)?->getStatus());
        self::assertCount(2, $this->courtTransport()->sent);
        self::assertSame(CaseStatus::DOSAR_INREGISTRAT, $this->case->getStatus());
    }

    public function testRegistrationWhileThePetitionIsUncertainWaitsForItThenCloses(): void
    {
        $submission = $this->uncertainAt(1, 3);

        $this->register();

        $this->em->refresh($submission);
        self::assertSame(FilingDeliveryStatus::NEEDS_REVIEW, $submission->getDeliveryStatus());
        self::assertSame(FilingPartOutcomeApplier::REVIEW_ANNEXES_CANCELLED_PENDING, $submission->getReviewReason());
        self::assertTrue($submission->isActive());
        self::assertSame(FilingPartStatus::CANCELLED, $submission->getPart(2)?->getStatus());
        self::assertSame(FilingPartStatus::CANCELLED, $submission->getPart(3)?->getStatus());

        $this->resolver()->declareReceived($submission->getPart(1) ?? throw new \LogicException(), $this->user, [UncertainPartResolver::CONFIRM_RECEIVED => true]);

        $this->em->refresh($submission);
        $this->em->refresh($this->case);
        self::assertSame(FilingDeliveryStatus::CLOSED_PARTIAL, $submission->getDeliveryStatus());
        self::assertNull($submission->getActiveLock());
        self::assertSame(CaseStatus::DOSAR_INREGISTRAT, $this->case->getStatus(), 'The late acceptance never moves a registered case back.');
    }

    public function testRegistrationWhileThePetitionIsUncertainThenRefusedEndsAsFailedNotPartial(): void
    {
        $submission = $this->uncertainAt(1, 3);
        $this->register();

        $this->resolver()->declareNotReceived($submission->getPart(1) ?? throw new \LogicException(), $this->user, [UncertainPartResolver::CONFIRM_NOT_RECEIVED => true]);

        $this->em->refresh($submission);
        self::assertSame(FilingDeliveryStatus::FAILED_INTERNAL, $submission->getDeliveryStatus(), 'Nothing left: it is not "the petition left and the rest was closed".');
        self::assertNull($submission->getActiveLock());
        self::assertNull($submission->getCancelledAt());
    }

    /** A submission of $emails emails whose email $sequence is uncertain (the earlier ones accepted). */
    private function uncertainAt(int $sequence, int $emails): FilingSubmission
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, $emails);
        $submission = $this->submitFiling($this->case, $this->user);
        for ($i = 1; $i < $sequence; ++$i) {
            $this->handleNextFilingMessage();
        }
        $this->failNext(RecordingCourtTransport::lostAfterData());
        $this->em->refresh($submission);
        self::assertSame(FilingPartStatus::UNCERTAIN, $submission->getPart($sequence)?->getStatus());
        self::assertSame(FilingDeliveryStatus::NEEDS_REVIEW, $submission->getDeliveryStatus());

        return $submission;
    }

    private function register(): void
    {
        $this->actAs($this->user);
        $this->case->setCourtCaseNumber('321/301/2026');
        static::getContainer()->get(CaseWorkflowService::class)->apply($this->case, 'inregistreaza_dosar');
        $this->em->flush();
    }

    private function failNext(\Throwable $failure): void
    {
        $this->courtTransport()->failNext = $failure;
        try {
            $this->handleNextFilingMessage();
        } catch (HandlerFailedException) {
            // Only the recorded state matters here.
        }
    }

    private function admin(): User
    {
        $this->admin = $this->createFilingUser($this->em, 'filing-admin');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        return $this->admin;
    }

    private function resolver(): UncertainPartResolver
    {
        return static::getContainer()->get(UncertainPartResolver::class);
    }
}
