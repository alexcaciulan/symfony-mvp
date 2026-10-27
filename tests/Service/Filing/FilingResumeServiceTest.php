<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\CourtEmailAddress;
use App\Entity\FilingAttempt;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CourtEmailStatus;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartStatus;
use App\Enum\NotificationType;
use App\Message\SendFilingEmailPartMessage;
use App\Service\Court\CourtEmailAddressService;
use App\Service\Filing\FilingResumeService;
use App\Service\Filing\FilingSendBlockedException;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use App\Tests\Support\RecordingCourtTransport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class FilingResumeServiceTest extends KernelTestCase
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
        $this->user = $this->createFilingUser($this->em, 'filing-resume');
        $this->courtTransport()->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
            if ($this->admin !== null) {
                $this->removeFilingFixtures($this->em, $this->admin, null);
            }
        }
        parent::tearDown();
    }

    public function testResumeSendsOnlyTheFailedAnnexInTheSameThread(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleNextFilingMessage();
        $this->failNext(RecordingCourtTransport::rcptRefused(550, '5.7.1 Message refused by policy'));

        $this->em->refresh($submission);
        self::assertSame(FilingDeliveryStatus::PARTIALLY_SENT, $submission->getDeliveryStatus());
        self::assertTrue($submission->isStalled());
        self::assertTrue($submission->isActive());
        self::assertCount(1, $this->notices(NotificationType::FILING_PARTIALLY_SENT));
        self::assertTrue($this->service()->isResumable($submission));

        $this->service()->resumeRemaining($submission, $this->user, [FilingResumeService::CONFIRM_RESUME => true], '127.0.0.1', hash('sha256', 's'));

        $queued = [...$this->courtQueue()->get()];
        self::assertCount(1, $queued);
        self::assertInstanceOf(SendFilingEmailPartMessage::class, $queued[0]->getMessage());
        self::assertSame($submission->getPart(2)?->getId(), $queued[0]->getMessage()->partId);

        $this->handleAllFilingMessages();

        $this->em->refresh($submission);
        $sent = $this->courtTransport()->sent;
        self::assertCount(2, $sent, 'The petition left once; only the annex was sent again.');
        $part2 = $submission->getPart(2);
        self::assertStringContainsString('Message-ID: ' . $part2?->getMessageId(), $sent[1]['bytes']);
        self::assertStringContainsString('In-Reply-To: ' . $submission->getPart(1)?->getMessageId(), $sent[1]['bytes']);
        self::assertSame(FilingPartStatus::ACCEPTED, $part2->getStatus());
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());
        self::assertFalse($submission->isActive());
        self::assertSame(1, $submission->getPart(1)?->getAttemptCount());
        self::assertContains('resume:' . FilingResumeService::CONFIRM_RESUME, array_column($submission->getConfirmations(), 'key'));
        self::assertCount(2, $this->em->getRepository(FilingAttempt::class)->findBy(['part' => $part2]), 'Each try keeps its own attempt.');
    }

    public function testResumeIsRefusedWhileARetryIsScheduled(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleNextFilingMessage();
        $this->failNext(RecordingCourtTransport::rcptRefused(451, 'Try again later'));

        $this->em->refresh($submission);
        self::assertSame(FilingPartStatus::RETRY_SCHEDULED, $submission->getPart(2)?->getStatus());
        self::assertFalse($this->service()->isResumable($submission));
        $this->expectRefusal('filing.resume.refused', $submission);
    }

    public function testResumeIsRefusedWhileAPartIsUncertain(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleNextFilingMessage();
        $this->failNext(RecordingCourtTransport::lostAfterData());

        $this->em->refresh($submission);
        self::assertSame(FilingPartStatus::UNCERTAIN, $submission->getPart(2)?->getStatus());
        $this->expectRefusal('filing.resume.refused', $submission);
    }

    public function testResumeIsRefusedWhenTheWorkerConditionsFail(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleNextFilingMessage();
        $this->failNext(RecordingCourtTransport::rcptRefused(554, 'Transaction failed'));
        $this->em->refresh($submission);
        foreach ($submission->getPart(2)?->getPackageParts() ?? [] as $packagePart) {
            unlink($this->filingUploadsDir . '/' . $packagePart->getSignedPath());
        }

        $this->expectRefusal('filing.status.review.signed_file_changed', $submission);
        self::assertSame([], [...$this->courtQueue()->get()]);
    }

    public function testAnAcceptedPetitionIsNeverReset(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleNextFilingMessage();

        $this->expectException(\LogicException::class);
        $submission->getPart(1)?->resetForResume();
    }

    public function testResendAfterTheAddressIsFixedReusesTheSignedPackage(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $first = $this->submitFiling($this->case, $this->user);
        $this->failNext(RecordingCourtTransport::rcptRefused(550, '5.1.1 User unknown'));

        $this->em->refresh($first);
        self::assertSame(FilingDeliveryStatus::FAILED_PERMANENT, $first->getDeliveryStatus());
        self::assertFalse($first->isActive());
        self::assertFalse($this->service()->isResumable($first), 'Nothing was accepted: a new submission is requested instead.');
        $package = $first->getPackage();
        self::assertSame(FilingPackageStatus::SIGNED, $package->getStatus());

        $address = $this->em->find(CourtEmailAddress::class, (int) $first->getCourtEmailAddress()?->getId()) ?? throw new \LogicException();
        self::assertSame(CourtEmailStatus::INVALID, $address->getStatus());
        $this->actAs($this->user);
        $addresses = static::getContainer()->get(CourtEmailAddressService::class);
        $addresses->reopen($address);
        self::assertNull($address->getInvalidatedBySubmission());
        // Only the admin puts an address back in use, after checking it.
        $this->admin = $this->createFilingUser($this->em, 'filing-resume-admin');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $addresses->validate($address, $this->admin, true);
        $addresses->setFilingDefault($address);

        $second = $this->submitFiling($this->case, $this->user);
        $this->handleAllFilingMessages();

        $this->em->refresh($second);
        self::assertNotSame($first->getId(), $second->getId());
        self::assertSame($package->getId(), $second->getPackage()->getId());
        self::assertSame(FilingDeliveryStatus::SENT, $second->getDeliveryStatus());
        self::assertCount(1, $this->courtTransport()->sent);
    }

    private function failNext(\Throwable $failure): void
    {
        $this->courtTransport()->failNext = $failure;
        try {
            $this->handleNextFilingMessage();
        } catch (HandlerFailedException) {
            // The worker would retry or drop it; the test only looks at the recorded state.
        }
    }

    private function expectRefusal(string $key, FilingSubmission $submission): void
    {
        try {
            $this->service()->resumeRemaining($submission, $this->user, [FilingResumeService::CONFIRM_RESUME => true], null, null);
            self::fail('The resume must be refused.');
        } catch (FilingSendBlockedException $e) {
            self::assertSame($key, $e->messageKey);
        }
    }

    private function service(): FilingResumeService
    {
        return static::getContainer()->get(FilingResumeService::class);
    }

    /** @return list<Notification> */
    private function notices(NotificationType $type): array
    {
        return $this->em->getRepository(Notification::class)->findBy(['user' => $this->user, 'type' => $type->value]);
    }
}
