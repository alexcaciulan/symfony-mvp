<?php

declare(strict_types=1);

namespace App\Service\Court;

use App\Entity\Court;
use App\Entity\CourtEmailAddress;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailReportReason;
use App\Enum\CourtEmailSource;
use App\Enum\CourtEmailStatus;
use App\Repository\AuditLogRepository;
use App\Repository\CourtEmailAddressRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every change to a court address. Confirming an address is the lawyer's (or the
 * admin's) own declaration, made with an explicit statement and kept verbatim; it
 * is a separate act from sending anything to the court. Each action is audited and
 * a nominal address never reaches the audit trail in clear.
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
    ) {}

    /** The statement exactly as the Romanian UI shows it next to the checkbox. */
    public function statementText(): string
    {
        return $this->translator->trans(self::STATEMENT_KEY, [], 'messages', 'ro');
    }

    public function validate(CourtEmailAddress $address, User $by, bool $statementConfirmed, ?LegalCase $context = null): void
    {
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
            if ($address->getSource() !== CourtEmailSource::MANUAL) {
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

    /** @param string $reason a fixed code, never free text that could carry personal data */
    public function markInvalid(CourtEmailAddress $address, string $reason, ?LegalCase $context = null): void
    {
        if (!in_array($address->getStatus(), [CourtEmailStatus::PROPOSED, CourtEmailStatus::VALIDATED], true)) {
            throw new CourtEmailActionRefusedException('court_address.validation.error.not_allowed_state');
        }
        $old = $this->snapshot($address);
        // The confirmation fields stay as the record of who relied on the address.
        $address->markInvalid($this->now(), $reason);

        $this->audit('court_email_invalidated', $address, $old, ['invalidReason' => $address->getInvalidReason()], $context);
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
     * A lawyer's report from a case: a confirmed address becomes invalid, a proposal
     * is rejected. Only the case id travels with it, never the case content.
     */
    public function reportWrong(CourtEmailAddress $address, CourtEmailReportReason $reason, LegalCase $case): void
    {
        match ($address->getStatus()) {
            CourtEmailStatus::VALIDATED => $this->markInvalid($address, 'reported_by_lawyer:' . $reason->value, $case),
            CourtEmailStatus::PROPOSED => $this->rejectReported($address, $reason, $case),
            default => throw new CourtEmailActionRefusedException('court_address.validation.error.not_allowed_state'),
        };
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

    public function addManual(Court $court, string $email, CourtEmailPurpose $purpose, ?LegalCase $context = null): CourtEmailAddress
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

        $address = new CourtEmailAddress($court, $email, $purpose, CourtEmailSource::MANUAL, $this->now());
        $this->em->persist($address);
        $this->em->flush();

        $this->audit('court_email_added', $address, null, [], $context);
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

    private function rejectReported(CourtEmailAddress $address, CourtEmailReportReason $reason, LegalCase $case): void
    {
        $old = $this->snapshot($address);
        $address->setStatus(CourtEmailStatus::REJECTED);
        $address->setFilingDefault(false);

        $this->audit('court_email_rejected', $address, $old, ['reportReason' => $reason->value], $case);
        $this->em->flush();
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
