<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseTransition;
use App\Service\AuditLogService;
use App\Service\Deadline\DeadlineService;
use App\Util\PiiMasker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Closes a case whose debtor paid the whole claim before the payment order request
 * was generated (from AMIABIL or SOMATIE_TRIMISA).
 *
 * The status change, the payment date and the closing of every open deadline run in
 * one transaction: a case must never end up closed with its limitation term still
 * open, since the alert cron would keep firing on it.
 */
final class CaseFullPaymentClosureService
{
    private const TRANSITION = CaseTransition::INCHIDE_PLATA_INTEGRALA->value;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CaseWorkflowService $workflowService,
        private readonly DeadlineService $deadlineService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function canClose(LegalCase $case): bool
    {
        return $this->workflowService->can($case, self::TRANSITION);
    }

    /**
     * @throws \DomainException when the case is past the stage where this closing applies
     */
    public function close(LegalCase $case, User $user, \DateTimeImmutable $paymentDate, ?string $amountReceived, ?string $details): void
    {
        if (!$this->canClose($case)) {
            throw new \DomainException('Full payment closure is not enabled for status ' . $case->getStatus()->value);
        }

        $this->em->wrapInTransaction(function () use ($case, $user, $paymentDate, $amountReceived, $details): void {
            $fromStatus = $case->getStatus()->value;

            $case->setFullPaymentDate($paymentDate);
            $this->workflowService->apply($case, self::TRANSITION);
            $this->em->flush();

            $closedDeadlines = $this->deadlineService->closeAllOpenOnFullPayment($case, $user);

            $this->auditLogService->log(
                action: 'case_closed',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: PiiMasker::maskCnpInArray([
                    'caseNumber' => $case->getCaseNumber(),
                    'reason' => 'PAID',
                    'fromStatus' => $fromStatus,
                    'transition' => self::TRANSITION,
                    'paymentDate' => $paymentDate->format('Y-m-d'),
                    'amountReceived' => $amountReceived,
                    'debtorCount' => $case->getDebtors()->count(),
                    'closedDeadlines' => $closedDeadlines,
                    'details' => PiiMasker::maskIban((string) $details),
                ]),
                category: AuditLogService::CATEGORY_CASE_CLOSED,
            );
            $this->em->flush();
        });
    }
}
