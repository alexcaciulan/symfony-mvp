<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\AuditLog;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingPartStatus;
use App\Message\SendFilingEmailPartMessage;
use App\Service\AuditLogService;
use App\Service\Filing\CourtFilingEmailFactory;
use App\Service\Document\PowerOfAttorneyService;
use App\Service\Filing\FilingPackageBlockedException;
use App\Service\Filing\FilingPackageBuilder;
use App\Service\Filing\FilingSendBlockedException;
use App\Service\Filing\FilingSubmissionService;
use App\Service\Filing\SignedPartUploadService;
use App\Service\Filing\SignedUploadRefusedException;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class FilingSubmissionServiceTest extends KernelTestCase
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
        $this->user = $this->createFilingUser($this->em, 'filing-submit');
        $this->courtTransport()->reset();
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testSubmitQueuesTheFirstEmailOnlyWithTheConfirmedStatements(): void
    {
        $submission = $this->submitFiling($this->case, $this->user);

        self::assertSame(FilingDeliveryStatus::QUEUED, $submission->getDeliveryStatus());
        self::assertSame(1, $submission->getActiveLock());
        self::assertSame(CourtFilingEmailFactory::TEMPLATE_VERSION, $submission->getTemplateVersion());
        self::assertSame([[
            'key' => FilingSubmissionService::CONFIRM_DOCUMENTS,
            'text' => 'Am verificat documentele semnate, textul e-mailului și adresa instanței.',
        ]], $submission->getConfirmations());
        self::assertSame('127.0.0.1', $submission->getInitiatedIp());
        self::assertSame('VALIDATED', $submission->getAddressStateSnapshot()['status']);
        self::assertSame('instanta-simulata@example.test', $submission->getToAddress());
        self::assertStringEndsWith('@example.test', $submission->getIntendedToAddress());
        self::assertSame(FilingPartStatus::PENDING, $submission->getPart(1)?->getStatus());
        self::assertMatchesRegularExpression('/^<filing\.[a-z2-7]{26}\.1@lexrecovery\.test>$/', (string) $submission->getPart(1)?->getMessageId());

        $queued = $this->courtQueue()->getSent();
        self::assertCount(1, $queued);
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(SendFilingEmailPartMessage::class, $message);
        self::assertSame($submission->getPart(1)?->getId(), $message->partId);

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'filing_submission_queued', 'entityId' => (string) $this->case->getId()]);
        self::assertSame(AuditLogService::CATEGORY_FILING_SUBMISSION, $audit?->getCategory());
        self::assertSame(hash('sha256', 'session'), $audit?->getNewData()['sessionHash']);
    }

    public function testSecondRequestReturnsTheSubmissionAlreadyHoldingTheCase(): void
    {
        $first = $this->submitFiling($this->case, $this->user);
        $second = $this->submitFiling($this->case, $this->user);

        self::assertSame($first->getId(), $second->getId());
        self::assertCount(1, $this->courtQueue()->getSent());
    }

    public function testUntickedStatementRefusesTheRequest(): void
    {
        try {
            $this->submitFiling($this->case, $this->user, [FilingSubmissionService::CONFIRM_DOCUMENTS => false]);
            self::fail('An unticked statement must refuse the transmission.');
        } catch (FilingSendBlockedException $e) {
            self::assertSame('filing.send.error.confirmation_required', $e->messageKey);
        }
        self::assertSame([], $this->courtQueue()->getSent());
    }

    public function testDownloadedArchiveRequiresTheOtherChannelStatement(): void
    {
        $this->actAs($this->user);
        static::getContainer()->get(AuditLogService::class)->log('zip_package_downloaded', LegalCase::class, (string) $this->case->getId());
        $this->em->flush();

        $texts = static::getContainer()->get(FilingSubmissionService::class)->confirmationTexts($this->case);
        self::assertArrayHasKey(FilingSubmissionService::CONFIRM_OTHER_CHANNEL, $texts);

        $this->expectException(FilingSendBlockedException::class);
        $this->submitFiling($this->case, $this->user);
    }

    public function testCancelBeforeAnythingLeftReleasesTheCase(): void
    {
        $submission = $this->submitFiling($this->case, $this->user);

        static::getContainer()->get(FilingSubmissionService::class)->cancel($submission, $this->user);

        self::assertSame(FilingDeliveryStatus::CANCELLED, $submission->getDeliveryStatus());
        self::assertFalse($submission->isActive());
        self::assertSame(FilingPartStatus::CANCELLED, $submission->getPart(1)?->getStatus());
    }

    public function testCancelIsRefusedOnceThePetitionWasAccepted(): void
    {
        $this->case = $this->recreateWithEmails(2);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleNextFilingMessage();

        $this->expectException(FilingSendBlockedException::class);
        static::getContainer()->get(FilingSubmissionService::class)->cancel($submission, $this->user);
    }

    public function testPackageIsFrozenWhileTheSubmissionHoldsTheCase(): void
    {
        $submission = $this->submitFiling($this->case, $this->user);
        $part = $submission->getPackage()->getParts()->first();

        try {
            static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
            self::fail('A package being transmitted must not be rebuilt.');
        } catch (FilingPackageBlockedException $e) {
            self::assertSame('filing.package.blocked_submission_active', $e->messageKey);
        }

        $file = sys_get_temp_dir() . '/' . uniqid('signed', true) . '.pdf';
        file_put_contents($file, '%PDF-1.4');
        try {
            static::getContainer()->get(SignedPartUploadService::class)->upload($part, new UploadedFile($file, 'semnat.pdf', 'application/pdf', null, true), $this->user, true);
            self::fail('A signed file must not be replaced while it is being transmitted.');
        } catch (SignedUploadRefusedException $e) {
            self::assertSame('filing.package.blocked_submission_active', $e->messageKey);
        } finally {
            @unlink($file);
        }

        self::assertFalse(static::getContainer()->get(PowerOfAttorneyService::class)->canEditDetails($this->case));
    }

    public function testCourtFilingTransportIsTheNullOneInTests(): void
    {
        self::assertSame('null://', (string) static::getContainer()->get('app.court_filing_transport'));
        self::assertInstanceOf(TransportInterface::class, static::getContainer()->get('app.court_filing_transport'));
        self::assertInstanceOf(InMemoryTransport::class, $this->courtQueue());
    }

    private function recreateWithEmails(int $emails): LegalCase
    {
        $this->removeFilingFixtures($this->em, $this->user, $this->case);
        $this->em->clear();
        $this->countyFixtureCache = [];
        $this->filingCourts = [];
        $this->user = $this->createFilingUser($this->em, 'filing-submit');

        return $this->createSendableFilingCase($this->em, $this->user, $emails);
    }
}
