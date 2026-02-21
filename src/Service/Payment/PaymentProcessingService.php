<?php

namespace App\Service\Payment;

use App\Entity\LegalCase;
use App\Enum\PaymentStatus;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use Doctrine\ORM\EntityManagerInterface;

class PaymentProcessingService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CaseWorkflowService $workflowService,
        private AuditLogService $auditLogService,
    ) {}

    public function processPayment(LegalCase $case): void
    {
        $reference = 'SIM-' . time();
        $paymentDetails = [];

        foreach ($case->getPayments() as $payment) {
            if ($payment->getStatus() === PaymentStatus::PENDING) {
                $payment->setStatus(PaymentStatus::COMPLETED);
                $payment->setPaymentMethod('simulator');
                $payment->setExternalReference($reference);
                $paymentDetails[] = [
                    'id' => $payment->getId(),
                    'type' => $payment->getPaymentType()->value,
                    'amount' => $payment->getAmount(),
                ];
            }
        }

        $this->workflowService->apply($case, 'confirm_payment');

        $this->auditLogService->log('payment_completed', 'LegalCase', (string) $case->getId(),
            ['status' => 'pending_payment'],
            [
                'status' => 'paid',
                'paymentMethod' => 'simulator',
                'externalReference' => $reference,
                'payments' => $paymentDetails,
            ]
        );

        $this->em->flush();
    }
}
