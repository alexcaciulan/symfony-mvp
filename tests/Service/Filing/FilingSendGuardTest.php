<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\Court;
use App\Entity\CourtEmailAddress;
use App\Entity\FilingPackage;
use App\Entity\LegalCase;
use App\Entity\PortalCaseMatch;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailStatus;
use App\Enum\FilingChannel;
use App\Enum\FilingPackageStatus;
use App\Enum\PortalCaseMatchSource;
use App\Enum\SubscriptionStatus;
use App\Repository\CourtEmailAddressRepository;
use App\Repository\FilingPackageRepository;
use App\Service\Filing\FilingSendGuard;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FilingSendGuardTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;

    private EntityManagerInterface $em;
    private User $user;
    private ?User $firstUser = null;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'filing-guard');
        $this->courtTransport()->reset();
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $this->actAs($this->user);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->executeStatement('DELETE FROM portal_case_match WHERE legal_case_id = ?', [(int) $this->case->getId()]);
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
            if ($this->firstUser !== null) {
                $this->removeFilingFixtures($this->em, $this->firstUser, null);
            }
        }
        parent::tearDown();
    }

    public function testFixtureCaseMayBeSent(): void
    {
        $verdict = $this->guard()->checkForSubmit($this->case, $this->user);

        self::assertSame([], $verdict->blockers);
        self::assertNotNull($verdict->address);
        self::assertSame(FilingPackageStatus::SIGNED, $verdict->package?->getStatus());
    }

    public function testUnverifiedUserIsBlocked(): void
    {
        $this->user->setIsVerified(false);

        $this->assertBlockedBy('filing.send.blocker.user_not_verified');
    }

    public function testLapsedSubscriptionIsBlocked(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE subscription SET status = ? WHERE user_id = ?', [SubscriptionStatus::SUSPENDED->value, $this->user->getId()]);

        $this->assertBlockedBy('filing.send.blocker.subscription_inactive');
    }

    public function testPackageNotSignedIsBlocked(): void
    {
        $this->package()->setStatus(FilingPackageStatus::AWAITING_SIGNATURES);

        $this->assertBlockedBy('filing.send.blocker.package_not_signed');
    }

    public function testPackageWhoseSourcesChangedIsBlocked(): void
    {
        $this->case->setCalculatedInterest('999.99');
        $this->em->flush();

        $this->assertBlockedBy('filing.send.blocker.package_stale');
    }

    public function testOnlyProposedAddressIsBlocked(): void
    {
        $this->address()->setStatus(CourtEmailStatus::PROPOSED);

        $this->assertBlockedBy('enum.court_filing_address_missing_reason.NOT_VALIDATED');
    }

    public function testExpiredValidationIsBlocked(): void
    {
        $address = $this->address();
        $address->markValidated($this->user, new \DateTimeImmutable('-200 days'), 'statement');

        $this->assertBlockedBy('enum.court_filing_address_missing_reason.VALIDATION_EXPIRED');
    }

    public function testCourtChangedAfterTheAddressIsBlocked(): void
    {
        $other = new Court();
        $other->setName('Judecătoria Test Filing ' . uniqid());
        $other->setCounty($this->case->getCourt()->getCounty());
        $other->setType($this->case->getCourt()->getType());
        $other->setActive(true);
        $this->em->persist($other);
        $this->filingCourts[] = $other;
        $this->createCourtEmailAddress($this->em, $other, 'alta-' . uniqid() . '@example.test', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-1 day'), $this->user, true);
        $this->case->setCourt($other);
        $this->em->flush();

        // The package was built for the first court, so it no longer matches either.
        $this->assertBlockedBy('filing.send.blocker.package_stale');
    }

    public function testOpenPortalMatchIsBlocked(): void
    {
        $match = new PortalCaseMatch($this->case, '999/301/2026', PortalCaseMatchSource::AUTO);
        $this->em->persist($match);
        $this->case->getPortalCaseMatches()->add($match);
        $this->em->flush();

        $this->assertBlockedBy('filing.send.blocker.portal_match');
    }

    public function testCourtCaseNumberIsBlocked(): void
    {
        $this->case->setCourtCaseNumber('1/301/2026');

        $this->assertBlockedBy('filing.send.blocker.court_case_number');
    }

    public function testManualFilingIsBlocked(): void
    {
        $this->case->setFilingChannel(FilingChannel::REGISTRATURA);
        $this->case->setFiledAt(new \DateTimeImmutable('-1 day'));

        $this->assertBlockedBy('filing.send.blocker.manual_filing');
    }

    public function testActiveSubmissionIsBlocked(): void
    {
        $this->submitFiling($this->case, $this->user);

        $this->assertBlockedBy('filing.send.blocker.submission_active');
    }

    public function testMissingServiceDetailsAreBlocked(): void
    {
        $this->case->setPaymentNoticeCommunicationMethod(null);

        $this->assertBlockedBy('filing.send.blocker.communication_details_missing');
    }

    public function testPaymentTermStillRunningIsBlocked(): void
    {
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-3 days'));

        $this->assertBlockedBy('case_overview.payment_order.flash_error_term_not_expired');
    }

    public function testLaterPartPassesOnTheMovedCaseAndFailsOnAChangedSignedFile(): void
    {
        $this->case = $this->recreateWithEmails(2);
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleNextFilingMessage();
        $this->em->refresh($submission);
        $second = $submission->getPart(2);
        self::assertNotNull($second);
        self::assertSame(CaseStatus::CERERE_DEPUSA, $this->case->getStatus());

        self::assertSame([], $this->guard()->checkForPart($second)->blockers);

        $annex = $second->getPackageParts()->first();
        file_put_contents($this->filingUploadsDir . '/' . $annex->getSignedPath(), 'changed');

        self::assertSame(['filing.status.review.signed_file_changed'], $this->guard()->checkForPart($second)->blockers);
    }

    private function assertBlockedBy(string $key): void
    {
        $verdict = $this->guard()->checkForSubmit($this->case, $this->user);

        self::assertFalse($verdict->isAllowed());
        self::assertContains($key, $verdict->blockers);
    }

    private function guard(): FilingSendGuard
    {
        return static::getContainer()->get(FilingSendGuard::class);
    }

    private function package(): FilingPackage
    {
        return static::getContainer()->get(FilingPackageRepository::class)->findCurrentForCase($this->case) ?? throw new \LogicException('No package.');
    }

    private function address(): CourtEmailAddress
    {
        return static::getContainer()->get(CourtEmailAddressRepository::class)->findForCourt($this->case->getCourt())[0];
    }

    /** A sendable case of another lawyer with this many emails; the teardown removes both. */
    private function recreateWithEmails(int $emails): LegalCase
    {
        $this->firstUser = $this->user;
        $this->user = $this->createFilingUser($this->em, 'filing-guard');

        return $this->createSendableFilingCase($this->em, $this->user, $emails);
    }
}
