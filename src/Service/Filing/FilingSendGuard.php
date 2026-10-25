<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingEmailPart;
use App\Entity\FilingPackage;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\PortalCaseMatch;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartRole;
use App\Enum\FilingPartStatus;
use App\Enum\PortalCaseMatchStatus;
use App\Repository\AuditLogRepository;
use App\Repository\CourtEmailAddressReportRepository;
use App\Repository\FilingSubmissionRepository;
use App\Security\Voter\CaseVoter;
use App\Service\Billing\SubscriptionService;
use App\Service\Case\DebtorSeatCourtCheck;
use App\Service\Court\CourtFilingAddressResolver;
use App\Service\Deadline\DeadlineService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The technical conditions for transmitting a case to the court by email,
 * checked when the lawyer asks and again in the worker before each email. It
 * does not check the qualified signature, the competence of the court or whether
 * a court case already exists: those stay the lawyer's and the court's. It does
 * make sure the address is one the lawyer confirmed, that nothing suggests the
 * petition was already filed elsewhere, and that what leaves is what was signed.
 */
final class FilingSendGuard
{
    private const INTEREST_DATE_WARNING_DAYS = 30;

    public function __construct(
        private readonly Security $security,
        private readonly SubscriptionService $subscriptions,
        private readonly FilingGate $gate,
        private readonly DeadlineService $deadlines,
        private readonly FilingPackageStalenessChecker $staleness,
        private readonly DebtorSeatCourtCheck $seatCheck,
        private readonly CourtFilingAddressResolver $addressResolver,
        private readonly FilingSubmissionRepository $submissions,
        private readonly CourtFilingEmailFactory $emailFactory,
        private readonly AuditLogRepository $auditLogs,
        private readonly CourtEmailAddressReportRepository $addressReports,
        private readonly ClockInterface $clock,
        private readonly int $filingMaxEmailBytes,
        private readonly string $filingEmailChannelSince,
        private readonly string $uploadsDir,
    ) {}

    /**
     * @param ?FilingSubmission $self the submission being sent, when checked from the worker:
     *                                it may hold the case, and there is no session to vote with
     */
    public function checkForSubmit(LegalCase $case, User $user, ?FilingSubmission $self = null): FilingSendVerdict
    {
        $blockers = [];
        $warnings = [];

        if ($case->getUser() !== $user || ($self === null && !$this->security->isGranted(CaseVoter::TRANSITION, $case))) {
            $blockers[] = 'filing.send.blocker.not_owner';
        }
        if (!$user->isVerified()) {
            $blockers[] = 'filing.send.blocker.user_not_verified';
        }
        if (!$this->subscriptions->getCurrentSubscription($user)?->getStatus()->isUsable()) {
            $blockers[] = 'filing.send.blocker.subscription_inactive';
        }
        if (trim((string) $user->getEmail()) === '' || trim((string) $user->getFullName()) === '') {
            $blockers[] = 'filing.send.blocker.lawyer_email_missing';
        }

        $gateBlocker = $this->gate->firstBlocker($case);
        if ($gateBlocker !== null) {
            $blockers[] = $gateBlocker;
        }
        // Stricter than the package gate: sending needs the receipt date and an accepted
        // way of service (bailiff or registered letter with acknowledgement).
        if ($case->getPaymentNoticeCommunicationDate() === null || $case->getPaymentNoticeCommunicationMethod() === null) {
            $blockers[] = 'filing.send.blocker.communication_details_missing';
        } elseif (!$this->deadlines->isPaymentTermExpired($case, $this->today())) {
            $blockers[] = 'case_overview.payment_order.flash_error_term_not_expired';
        }

        $package = $this->staleness->refresh($case);
        if ($package === null || $package->getStatus() === FilingPackageStatus::STALE) {
            $blockers[] = $package === null ? 'filing.send.blocker.package_not_signed' : 'filing.send.blocker.package_stale';
        } elseif ($package->getStatus() !== FilingPackageStatus::SIGNED || !$package->allPartsSignatureAccepted()) {
            $blockers[] = 'filing.send.blocker.package_not_signed';
        } else {
            foreach ($this->packageBlockers($package) as $key) {
                $blockers[] = $key;
            }
            $warnings = $this->warnings($package);
        }
        if ($self !== null && $package !== null && $package->getId() !== $self->getPackage()->getId()) {
            $blockers[] = 'filing.status.review.package_changed';
        }

        // The platform computed the court from the case data; the competence itself is for the court.
        if ($this->seatCheck->courtNowPointedTo($case) !== null) {
            $blockers[] = 'filing.send.blocker.court_changed';
        }

        $resolved = $this->addressResolver->resolve($case);
        $address = $resolved->address;
        if ($address === null) {
            $blockers[] = $resolved->reason?->label() ?? 'filing.send.blocker.address_missing';
        } elseif ($address->getCourt()->getId() !== $case->getCourt()?->getId()) {
            $blockers[] = 'filing.send.blocker.address_court_differs';
        } elseif ($self !== null && $self->getCourtEmailAddress()?->getId() !== $address->getId()) {
            $blockers[] = 'filing.status.review.address_changed';
        } elseif ($this->addressReports->hasOpenDeliveryReportForCase($address, $case)) {
            // This case's filing came back from that address: not again until the admin has checked it.
            $blockers[] = 'filing.send.blocker.address_bounce_reported';
        }

        $active = $this->submissions->findActiveForCase($case);
        if ($active !== null && $active->getId() !== $self?->getId()) {
            $blockers[] = 'filing.send.blocker.submission_active';
        }
        if ($this->hasOpenPortalMatch($case)) {
            $blockers[] = 'filing.send.blocker.portal_match';
        }
        if ($case->getCourtCaseNumber() !== null) {
            $blockers[] = 'filing.send.blocker.court_case_number';
        }
        if ($case->getFiledAt() !== null || $case->getFilingChannel() !== null) {
            $blockers[] = 'filing.send.blocker.manual_filing';
        }
        if ($this->emailFactory->powerOfAttorneyDetails($case) === null) {
            $blockers[] = 'filing.send.blocker.power_of_attorney_details';
        }

        return new FilingSendVerdict(array_values(array_unique($blockers)), $warnings, $address, $package);
    }

    /**
     * Before each email in the worker. The first one carries the petition and
     * re-runs every condition; later ones only check that nothing changed under
     * what was already sent.
     */
    public function checkForPart(FilingEmailPart $part): FilingSendVerdict
    {
        $submission = $part->getSubmission();
        $case = $submission->getLegalCase();
        if ($part->isFirst()) {
            return $this->checkForSubmit($case, $submission->getInitiatedBy(), $submission);
        }

        $blockers = [];
        if (!$submission->isActive()) {
            $blockers[] = 'filing.status.review.not_active';
        }
        if ($part->getStatus() === FilingPartStatus::CANCELLED) {
            $blockers[] = 'filing.status.review.part_cancelled';
        }
        // A registration that follows from this filing does not stop the annexes it lists.
        $registeredFromThis = $case->getStatus() === CaseStatus::DOSAR_INREGISTRAT
            && FilingSubmissionService::isRegisteredFromThisFiling($case, $submission);
        if (!$registeredFromThis && ($case->getCourtCaseNumber() !== null || $case->getStatus() !== CaseStatus::CERERE_DEPUSA)) {
            $blockers[] = 'filing.status.review.case_moved_on';
        }
        $package = $submission->getPackage();
        if ($package->getStatus() !== FilingPackageStatus::SENT) {
            $blockers[] = 'filing.status.review.package_changed';
        }
        foreach ($part->getPackageParts() as $packagePart) {
            $path = $packagePart->getSignedPath();
            $bytes = $path !== null ? @file_get_contents($this->uploadsDir . '/' . $path) : false;
            if ($bytes === false || hash('sha256', $bytes) !== $packagePart->getSignedSha256()) {
                $blockers[] = 'filing.status.review.signed_file_changed';
                break;
            }
        }

        return new FilingSendVerdict($blockers, [], $submission->getCourtEmailAddress(), $package);
    }

    /**
     * The case may already have gone to the court another way: its petition predates
     * filing by email, or its alternative-channel archive was downloaded.
     */
    public function requiresOtherChannelConfirmation(LegalCase $case): bool
    {
        $generatedAt = $case->requestGeneratedAt();
        if ($generatedAt !== null && $generatedAt < new \DateTimeImmutable($this->filingEmailChannelSince)) {
            return true;
        }

        return $this->auditLogs->hasActionForCase($case, 'zip_package_downloaded');
    }

    /** @return list<string> */
    private function packageBlockers(FilingPackage $package): array
    {
        $blockers = [];
        $emails = $this->emailFactory->emailsOf($package);
        $first = $emails[0] ?? [];
        $roles = array_map(static fn ($p): FilingPartRole => $p->getRole(), $first);
        if (!in_array(FilingPartRole::PETITION, $roles, true) || !in_array(FilingPartRole::POWER_OF_ATTORNEY, $roles, true)) {
            $blockers[] = 'filing.send.blocker.first_email_layout';
        }

        foreach ($package->getSizePlan()['emails'] ?? [] as $email) {
            if (($email['encodedBytes'] ?? 0) > $this->filingMaxEmailBytes) {
                $blockers[] = 'filing.send.blocker.email_too_large';
                break;
            }
        }

        return $blockers;
    }

    /** @return list<array{key: string, params: array<string, string>}> */
    private function warnings(FilingPackage $package): array
    {
        $warnings = [];
        foreach ($package->getParts() as $part) {
            if (in_array('filing.signature.warning.cn_differs', $part->getSignatureWarnings(), true)) {
                $warnings[] = ['key' => 'filing.send.warning.cn_differs', 'params' => []];
                break;
            }
        }

        $reference = $package->getSourceManifest()['accessoryReferenceDate'] ?? null;
        if (is_string($reference) && ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $reference)) !== false
            && $date->modify('+' . self::INTEREST_DATE_WARNING_DAYS . ' days') < $this->today()) {
            $warnings[] = ['key' => 'filing.send.warning.interest_date_old', 'params' => ['%date%' => $date->format('d.m.Y')]];
        }

        return $warnings;
    }

    private function hasOpenPortalMatch(LegalCase $case): bool
    {
        return $case->getPortalCaseMatches()->exists(
            static fn (int $_k, PortalCaseMatch $m): bool => in_array($m->getStatus(), [PortalCaseMatchStatus::PROPOSED, PortalCaseMatchStatus::ACCEPTED], true),
        );
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }
}
