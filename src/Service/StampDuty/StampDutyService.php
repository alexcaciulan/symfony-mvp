<?php

declare(strict_types=1);

namespace App\Service\StampDuty;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DocumentType;
use App\Enum\StampDutyStatus;
use App\Service\AuditLogService;
use App\Service\Deadline\DeadlineService;
use App\Service\Document\DocumentUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Records what happened with the judicial stamp duty. The platform never collects
 * the money (that would put client funds through a third party and touch payment-services
 * regulation), so everything here is evidence and state, not a transaction: the lawyer
 * pays at the official channel and brings the proof back.
 */
final class StampDutyService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentUploadService $documentUploadService,
        private readonly DeadlineService $deadlineService,
        private readonly AuditLogService $auditLogService,
        private readonly StampDutyUatResolver $uatResolver,
    ) {}

    /**
     * Attaches the proof of payment and marks the duty paid. The UAT is snapshotted
     * from what we advised at this moment, because the creditor's registered office
     * can move later and the account it was paid into is what a dispute is about.
     */
    public function recordPayment(
        LegalCase $case,
        UploadedFile $file,
        UserInterface $user,
        \DateTimeImmutable $paidAt,
        ?string $paidAmount,
        ?string $payerName,
        ?string $paymentReference,
        string $lawVersion,
    ): Document {
        // The upload commits on its own flush, so it cannot join the transaction below.
        // If the payment state then fails to persist, the case would be left holding a
        // proof while still reading NEACHITATA, and the re-upload guard (which keys off
        // the status) would happily accept a second proof. Compensate by removing it.
        $document = $this->documentUploadService->upload($case, $file, DocumentType::DOVADA_TAXA_TIMBRU, $user);

        $target = $this->uatResolver->resolve($case);

        try {
            $this->em->wrapInTransaction(function () use ($case, $document, $paidAt, $paidAmount, $payerName, $paymentReference, $lawVersion, $target): void {
                $case->setStampDutyStatus(StampDutyStatus::ACHITATA);
                $case->setStampDutyPaidAt($paidAt);
                $case->setStampDutyPaidAmount($paidAmount);
                $case->setStampDutyPayerName($payerName);
                $case->setStampDutyPaymentReference($paymentReference);
                $case->setStampDutyUat($target->uatName());
                $case->setStampDutyLawVersion($lawVersion);
                $this->em->flush();

                $this->auditLogService->log(
                    action: 'stamp_duty_paid',
                    entityType: LegalCase::class,
                    entityId: (string) $case->getId(),
                    newData: [
                        'caseNumber' => $case->getCaseNumber(),
                        'documentId' => $document->getId(),
                        'paidAt' => $paidAt->format('Y-m-d'),
                        'paidAmount' => $paidAmount,
                        'payerName' => $payerName,
                        'paymentReference' => $paymentReference,
                        'uat' => $target->uatName(),
                        'uatStatus' => $target->status->value,
                        'lawVersion' => $lawVersion,
                    ],
                    category: AuditLogService::CATEGORY_STAMP_DUTY,
                );
                $this->em->flush();
            });
        } catch (\Throwable $e) {
            $case->getDocuments()->removeElement($document);
            $this->documentUploadService->delete($document);

            throw $e;
        }

        return $document;
    }

    /**
     * The lawyer knowingly files without proof and will stamp when the court asks
     * (OUG 80/2013 art. 33 alin. 2 allows it). Legal, but it puts a 10-day
     * annulment clock on the case, so the choice is recorded rather than blocked.
     */
    public function deferToRegularization(LegalCase $case, UserInterface $user): void
    {
        // The audit entry IS the record of the lawyer's choice. A case that lands in
        // AMANATA_REGULARIZARE without it would show a filed-unstamped dosar with
        // nobody accountable for the decision, so the two commit together or not at all.
        $this->em->wrapInTransaction(function () use ($case, $user): void {
            $case->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
            $this->em->flush();

            $this->auditLogService->log(
                action: 'stamp_duty_deferred_to_regularization',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'acknowledgedBy' => $user->getUserIdentifier(),
                ],
                category: AuditLogService::CATEGORY_STAMP_DUTY,
            );
            $this->em->flush();
        });
    }

    /**
     * The lawyer will pay in the electronic registry form, which takes the duty and
     * the petition together. Nothing is owed differently and no term starts: this
     * only unblocks the package, because the package is what carries the payment.
     */
    public function declarePaymentAtFiling(LegalCase $case, UserInterface $user): void
    {
        $this->em->wrapInTransaction(function () use ($case, $user): void {
            $case->setStampDutyStatus(StampDutyStatus::ACHITARE_LA_DEPUNERE);
            $this->em->flush();

            $this->auditLogService->log(
                action: 'stamp_duty_declared_at_filing',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'declaredBy' => $user->getUserIdentifier(),
                ],
                category: AuditLogService::CATEGORY_STAMP_DUTY,
            );
            $this->em->flush();
        });
    }

    /**
     * Payment went through the electronic registry, which transmits the confirmation
     * to the court together with the petition (OUG 80/2013 art. 40 alin. 3, as added
     * by Legea 268/2024). There is no file to attach here: the proof is already where
     * it needs to be, and asking for it a second time would be asking for nothing.
     *
     * The UAT snapshot is still taken, because what matters in a dispute is which
     * town hall we pointed at when the money moved.
     */
    public function confirmPaymentThroughRegistry(
        LegalCase $case,
        UserInterface $user,
        \DateTimeImmutable $paidAt,
        ?string $paymentReference,
        string $lawVersion,
    ): void {
        $target = $this->uatResolver->resolve($case);

        $this->em->wrapInTransaction(function () use ($case, $user, $paidAt, $paymentReference, $lawVersion, $target): void {
            $case->setStampDutyStatus(StampDutyStatus::ACHITATA);
            $case->setStampDutyPaidAt($paidAt);
            $case->setStampDutyPaymentReference($paymentReference);
            $case->setStampDutyUat($target->uatName());
            $case->setStampDutyLawVersion($lawVersion);
            $this->em->flush();

            $this->auditLogService->log(
                action: 'stamp_duty_paid_through_registry',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'paidAt' => $paidAt->format('Y-m-d'),
                    'paymentReference' => $paymentReference,
                    'uat' => $target->uatName(),
                    'confirmedBy' => $user->getUserIdentifier(),
                    'lawVersion' => $lawVersion,
                ],
                category: AuditLogService::CATEGORY_STAMP_DUTY,
            );
            $this->em->flush();
        });
    }

    /**
     * The court's notice to stamp has arrived. Only now can the 10-day term be
     * computed: it runs from that communication, a date the platform cannot observe.
     */
    public function recordCourtNotice(
        LegalCase $case,
        \DateTimeImmutable $noticeDate,
        ?int $grantedDays = null,
    ): LegalDeadline {
        return $this->em->wrapInTransaction(function () use ($case, $noticeDate, $grantedDays): LegalDeadline {
            $deadline = $this->deadlineService->createStampDutyDeadline($case, $noticeDate, $grantedDays);

            $this->auditLogService->log(
                action: 'stamp_duty_court_notice_recorded',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'courtNoticeDate' => $noticeDate->format('Y-m-d'),
                    'grantedDays' => $grantedDays,
                    'deadlineDate' => $deadline->getDeadlineDate()->format('Y-m-d'),
                    'deadlineId' => $deadline->getId(),
                ],
                category: AuditLogService::CATEGORY_STAMP_DUTY,
            );
            $this->em->flush();

            return $deadline;
        });
    }

    /**
     * Whether the proof names someone other than the claimant. Art. 40 alin. 3
     * presumes payment from a transfer order "signed by the debtor of the duty",
     * and the claimant is that debtor, so a mismatch is worth flagging. Advisory:
     * the lawyer may have legitimate reasons (the client paid from a group account).
     */
    public function payerDiffersFromCreditor(LegalCase $case, ?string $payerName): bool
    {
        $creditorName = $case->getCreditor()?->getName();
        if ($payerName === null || $payerName === '' || $creditorName === null) {
            return false;
        }

        return mb_strtolower(trim($payerName)) !== mb_strtolower(trim($creditorName));
    }
}
