<?php

declare(strict_types=1);

namespace App\Service\Court;

use App\Entity\Court;
use App\Entity\CourtEmailAddress;
use App\Entity\CourtEmailAddressReport;
use App\Entity\FilingSubmission;
use App\Entity\InboundMail;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailReportKind;
use App\Enum\CourtEmailReportReason;
use App\Enum\CourtEmailReportResolution;
use App\Enum\CourtEmailSource;
use App\Enum\CourtEmailStatus;
use App\Enum\NotificationType;
use App\Repository\AuditLogRepository;
use App\Repository\CourtEmailAddressReportRepository;
use App\Repository\CourtEmailAddressRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogService;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every change to a court address. An address serves every lawyer's filings at
 * that court, so only an admin adds, confirms or takes one out of use: the
 * confirmation says the admin checked the address on the source shown, with the
 * statement kept verbatim, and it is a separate act from sending anything. A
 * lawyer may only suggest an address (it waits, proposed, in the admin's queue)
 * or report one as wrong (the report goes to the admin; the address stays in use
 * until the admin decides). The only automatic removal is the platform's own SMTP
 * refusal at RCPT. Each action is audited and a nominal address never reaches the
 * audit trail in clear.
 */
class CourtEmailAddressService
{
    public const STATEMENT_KEY = 'court_address.validation.statement';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CourtEmailAddressRepository $addresses,
        private readonly AuditLogRepository $auditLogs,
        private readonly AuditLogService $auditLogService,
        private readonly ValidatorInterface $validator,
        private readonly CourtEmailPersonalDetector $personalDetector,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly CourtEmailAddressReportRepository $reports,
        private readonly UserRepository $users,
        private readonly NotificationDispatcherInterface $dispatcher,
    ) {}

    /** The statement exactly as the Romanian UI shows it next to the checkbox. */
    public function statementText(): string
    {
        return $this->translator->trans(self::STATEMENT_KEY, [], 'messages', 'ro');
    }

    public function validate(CourtEmailAddress $address, User $by, bool $statementConfirmed, ?LegalCase $context = null): void
    {
        self::requireAdmin($by);
        if (!$statementConfirmed) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.statement_required');
        }
        $now = $this->now();
        $revalidation = $address->getStatus() === CourtEmailStatus::VALIDATED && $address->isValidationExpired($now);
        if ($address->isPersonal() || !($address->getStatus() === CourtEmailStatus::PROPOSED || $revalidation)) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.not_allowed_state');
        }
        // Only a fresh sighting in the source clears this; a new confirmation cannot.
        if ($address->getMissingSince() !== null) {
            if (!$address->getSource()->isTypedIn()) {
                throw new CourtEmailActionRefusedException('court_address.validation.error.missing_on_source');
            }
            $address->setMissingSince(null);
        }

        $old = $this->snapshot($address);
        $address->markValidated($by, $now, $this->statementText());
        if ($address->getPurpose()->canReceiveFilings() && $this->otherDefaults($address) === []) {
            $address->setFilingDefault(true);
        }

        $this->audit($revalidation ? 'court_email_revalidated' : 'court_email_validated', $address, $old, [
            'validationStatement' => $address->getValidationStatement(),
        ], $context);
        $this->em->flush();
    }

    public function reject(CourtEmailAddress $address): void
    {
        if ($address->getStatus() !== CourtEmailStatus::PROPOSED) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.not_allowed_state');
        }
        $old = $this->snapshot($address);
        $address->setStatus(CourtEmailStatus::REJECTED);
        $address->setFilingDefault(false);

        $this->audit('court_email_rejected', $address, $old);
        $this->em->flush();
    }

    /** At most one default per (court, purpose): the others lose it in the same flush. */
    public function setFilingDefault(CourtEmailAddress $address): void
    {
        if (!$address->isUsableForFiling($this->now())) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.not_allowed_state');
        }
        $old = $this->snapshot($address);
        foreach ($this->otherDefaults($address) as $other) {
            $other->setFilingDefault(false);
        }
        $address->setFilingDefault(true);

        $this->audit('court_email_default_set', $address, $old);
        $this->em->flush();
    }

    /**
     * @param string               $reason  a fixed code, never free text that could carry personal data
     * @param ?FilingSubmission    $by      the court filing whose SMTP rejection caused it
     * @param array<string, mixed> $details SMTP code, enhanced status and the like, for the audit
     */
    public function markInvalid(CourtEmailAddress $address, string $reason, ?LegalCase $context = null, ?FilingSubmission $by = null, array $details = [], ?InboundMail $byInbound = null): void
    {
        if (!in_array($address->getStatus(), [CourtEmailStatus::PROPOSED, CourtEmailStatus::VALIDATED], true)) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.not_allowed_state');
        }
        $old = $this->snapshot($address);
        // The confirmation fields stay as the record of who relied on the address.
        $address->markInvalid($this->now(), $reason, $by, $byInbound);

        $extra = ['invalidReason' => $address->getInvalidReason()];
        if ($by !== null) {
            $extra['submissionId'] = $by->getId();
        }
        if ($byInbound !== null) {
            $extra['inboundMailId'] = $byInbound->getId();
        }
        $this->audit('court_email_invalidated', $address, $old, $extra + $details, $context ?? $by?->getLegalCase());
        $this->em->flush();
    }

    public function reopen(CourtEmailAddress $address): void
    {
        if (!in_array($address->getStatus(), [CourtEmailStatus::INVALID, CourtEmailStatus::REJECTED], true)) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.not_allowed_state');
        }
        $old = $this->snapshot($address);
        $address->setStatus(CourtEmailStatus::PROPOSED);
        $address->setFilingDefault(false);
        $address->clearValidation();
        $address->clearInvalidation();

        $this->audit('court_email_reopened', $address, $old);
        $this->em->flush();
    }

    /**
     * A lawyer's report from a case. It goes to the admin and changes nothing on
     * the address, which stays in use until the admin decides. Only the case id
     * travels with it, never the case content. A second open report by the same
     * lawyer on the same address is not added.
     */
    public function report(CourtEmailAddress $address, CourtEmailReportReason $reason, LegalCase $case, User $by): CourtEmailAddressReport
    {
        if (!in_array($address->getStatus(), [CourtEmailStatus::PROPOSED, CourtEmailStatus::VALIDATED], true)) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.not_allowed_state');
        }
        $open = $this->reports->findOpenByLawyer($address, $by);
        if ($open !== null) {
            return $open;
        }

        $report = (new CourtEmailAddressReport($address, CourtEmailReportKind::LAWYER, $reason, $this->now()))->setContext($case, $by);
        $this->em->persist($report);
        $this->em->flush();

        $this->audit('court_email_reported', $address, null, ['reportId' => $report->getId(), 'reportReason' => $reason->value], $case);
        $this->notifyAdmins(NotificationType::COURT_EMAIL_REPORTED, 'court_email_reported', $address, 'report:' . $report->getId());
        $this->em->flush();

        return $report;
    }

    /**
     * A delivery report says the mailbox does not exist. It may be forged or come
     * from an internal redirect, so it is the admin who decides; the address stays
     * in use meanwhile.
     */
    public function reportFromDeliveryReport(CourtEmailAddress $address, FilingSubmission $submission, InboundMail $mail, ?string $enhancedStatus): CourtEmailAddressReport
    {
        foreach ($this->reports->findOpenForAddress($address) as $open) {
            if ($open->getKind() === CourtEmailReportKind::DELIVERY_REPORT && $open->getSubmission()?->getId() === $submission->getId()) {
                return $open;
            }
        }

        $report = (new CourtEmailAddressReport($address, CourtEmailReportKind::DELIVERY_REPORT, CourtEmailReportReason::BOUNCED, $this->now()))
            ->setContext($submission->getLegalCase(), null, $submission, $mail, $enhancedStatus);
        $this->em->persist($report);
        $this->em->flush();

        $this->audit('court_email_delivery_reported', $address, null, [
            'reportId' => $report->getId(),
            'submissionId' => $submission->getId(),
            'inboundMailId' => $mail->getId(),
            'enhancedStatus' => $enhancedStatus,
            'recipientOverridden' => $submission->isRecipientOverridden(),
        ], $submission->getLegalCase());
        $this->notifyAdmins(NotificationType::COURT_EMAIL_REPORTED, 'court_email_reported', $address, 'report:' . $report->getId());
        $this->em->flush();

        return $report;
    }

    /**
     * The admin's decision on a report: dismissed (the address stays in use), or
     * the address is taken out of use, which also closes the other open reports on it.
     */
    public function resolveReport(CourtEmailAddressReport $report, User $admin, bool $invalidate): void
    {
        self::requireAdmin($admin);
        if (!$report->isOpen()) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.not_allowed_state');
        }
        $address = $report->getAddress();
        $now = $this->now();
        if (!$invalidate) {
            $report->resolve($admin, CourtEmailReportResolution::DISMISSED, $now);
            $this->audit('court_email_report_dismissed', $address, null, ['reportId' => $report->getId()], $report->getLegalCase());
            $this->em->flush();

            return;
        }

        if (in_array($address->getStatus(), [CourtEmailStatus::PROPOSED, CourtEmailStatus::VALIDATED], true)) {
            $this->markInvalid(
                $address,
                'admin_report:' . $report->getKind()->value . ':' . $report->getReason()->value,
                $report->getLegalCase(),
                $report->getSubmission(),
                ['reportId' => $report->getId(), 'enhancedStatus' => $report->getEnhancedStatus()],
                $report->getInboundMail(),
            );
        }
        foreach ($this->reports->findOpenForAddress($address) as $open) {
            $open->resolve($admin, CourtEmailReportResolution::INVALIDATED, $now);
        }
        $this->em->flush();
    }

    /**
     * Admin correction of what the address is for. Any purpose change sends a
     * confirmed address back to PROPOSED and drops its default.
     */
    public function changePurpose(CourtEmailAddress $address, CourtEmailPurpose $purpose, ?string $sectionLabel): void
    {
        $old = $this->snapshot($address);
        $sectionLabel = $sectionLabel !== null && trim($sectionLabel) !== '' ? mb_substr(trim($sectionLabel), 0, 255) : null;
        if ($purpose === $address->getPurpose() && $sectionLabel === $address->getSectionLabel()) {
            return;
        }

        $purposeChanged = $purpose !== $address->getPurpose();
        $address->setPurpose($purpose);
        $address->setSectionLabel($sectionLabel);
        // The lawyer's statement covered the former purpose only, so it cannot carry over.
        if ($purposeChanged) {
            $address->setFilingDefault(false);
            if ($address->getStatus() === CourtEmailStatus::VALIDATED) {
                $address->setStatus(CourtEmailStatus::PROPOSED);
            }
            $address->clearValidation();
        }

        $this->audit('court_email_purpose_changed', $address, $old, ['sectionLabel' => $sectionLabel]);
        $this->em->flush();
    }

    /** Added by an admin: proposed until the admin confirms it on its source. */
    public function addByAdmin(Court $court, string $email, CourtEmailPurpose $purpose, User $admin): CourtEmailAddress
    {
        self::requireAdmin($admin);
        $address = $this->create($court, $email, $purpose, CourtEmailSource::MANUAL);
        $this->audit('court_email_added', $address, null);
        $this->em->flush();

        return $address;
    }

    /**
     * A lawyer's suggestion from a case: it waits, proposed and unusable, in the
     * admin's queue. Nothing about the case travels with it beyond its id.
     */
    public function suggestByLawyer(Court $court, string $email, CourtEmailPurpose $purpose, LegalCase $case, User $lawyer): CourtEmailAddress
    {
        $address = $this->create($court, $email, $purpose, CourtEmailSource::LAWYER_SUGGESTION);
        $this->audit('court_email_suggested', $address, null, ['suggestedById' => $lawyer->getId()], $case);
        $this->notifyAdmins(NotificationType::COURT_EMAIL_SUGGESTED, 'court_email_suggested', $address, 'suggestion:' . $address->getId());
        $this->em->flush();

        return $address;
    }

    private function create(Court $court, string $email, CourtEmailPurpose $purpose, CourtEmailSource $source): CourtEmailAddress
    {
        $email = CourtEmailAddress::normalizeEmail($email);
        if ($purpose === CourtEmailPurpose::PERSONAL) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.personal_refused');
        }
        $violations = $this->validator->validate($email, [
            new Assert\NotBlank(),
            new Assert\Length(max: 254),
            new Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT),
        ]);
        if (count($violations) > 0) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.invalid_format');
        }
        // Not stored at all: keeping a nominal address would be processing personal data for nothing.
        if ($this->personalDetector->isNominal($email)) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.personal_refused');
        }
        if ($this->addresses->findOneForCourtByEmail($court, $email) !== null) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.duplicate');
        }

        $address = new CourtEmailAddress($court, $email, $purpose, $source, $this->now());
        $this->em->persist($address);
        $this->em->flush();

        return $address;
    }

    /**
     * Erasure of a nominal address: the row goes, and so does the address from every
     * earlier audit payload. What remains is the fact of the erasure and a hash.
     */
    public function erasePersonal(CourtEmailAddress $address): void
    {
        if (!$address->isPersonal()) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.not_allowed_state');
        }
        $id = (string) $address->getId();
        $email = $address->getEmail();
        $courtId = $address->getCourt()->getId();

        $this->auditLogs->redactEntityPayloads(CourtEmailAddress::class, $id, [$email => '[erased]']);
        $this->em->remove($address);
        $this->auditLogService->log(
            action: 'court_email_personal_erased',
            entityType: CourtEmailAddress::class,
            entityId: $id,
            newData: ['courtId' => $courtId, 'purpose' => CourtEmailPurpose::PERSONAL->value, 'sha256' => hash('sha256', $email)],
            category: AuditLogService::CATEGORY_COURT_EMAIL,
        );
        $this->em->flush();
    }

    /** Checked here, not only on the route: an address serves every lawyer's filings. */
    private static function requireAdmin(User $user): void
    {
        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.admin_only');
        }
    }

    private function notifyAdmins(NotificationType $type, string $key, CourtEmailAddress $address, string $dedup): void
    {
        $params = ['%court%' => $address->getCourt()->getName(), '%email%' => $address->getEmail()];
        foreach ($this->users->findAdmins() as $admin) {
            $this->dispatcher->dispatch(new NotificationDispatch(
                user: $admin,
                legalCase: null,
                type: $type,
                title: $this->translator->trans('notification.' . $key . '.title', $params),
                message: $this->translator->trans('notification.' . $key . '.message', $params),
                resourceLink: null,
                variant: 'warning',
                emailSubject: null,
                emailTemplate: null,
                dedupKey: sprintf('%s:%s:%d', $key, $dedup, (int) $admin->getId()),
            ));
        }
    }

    /** @return list<CourtEmailAddress> */
    private function otherDefaults(CourtEmailAddress $address): array
    {
        return array_values(array_filter(
            $this->addresses->findDefaultsForCourtAndPurpose($address->getCourt(), $address->getPurpose()),
            static fn (CourtEmailAddress $a): bool => $a !== $address,
        ));
    }

    /** @return array<string, mixed> */
    private function snapshot(CourtEmailAddress $address): array
    {
        return [
            'status' => $address->getStatus()->value,
            'purpose' => $address->getPurpose()->value,
            'filingDefault' => $address->isFilingDefault(),
        ];
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>      $extra
     */
    private function audit(string $action, CourtEmailAddress $address, ?array $old, array $extra = [], ?LegalCase $context = null): void
    {
        $new = $this->snapshot($address) + ['courtId' => $address->getCourt()->getId()];
        if (!$address->isPersonal()) {
            $new['email'] = $address->getEmail();
        }
        if ($context !== null) {
            $new['caseId'] = $context->getId();
        }

        $this->auditLogService->log(
            action: $action,
            entityType: CourtEmailAddress::class,
            entityId: (string) $address->getId(),
            oldData: $old,
            newData: $new + $extra,
            category: AuditLogService::CATEGORY_COURT_EMAIL,
        );
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}
