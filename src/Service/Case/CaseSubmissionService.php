<?php

namespace App\Service\Case;

use App\Entity\LegalCase;
use App\Entity\Payment;
use App\Enum\PaymentStatus;
use App\Enum\PaymentType;
use App\Service\Document\PdfGeneratorService;
use Doctrine\ORM\EntityManagerInterface;

class CaseSubmissionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private TaxCalculatorService $taxCalculator,
        private CaseWorkflowService $workflowService,
        private PdfGeneratorService $pdfGenerator,
    ) {}

    public function calculateFees(LegalCase $case): void
    {
        $claimAmount = (float) $case->getClaimAmount();

        if ($claimAmount <= 0) {
            return;
        }

        $fees = $this->taxCalculator->calculate($claimAmount);
        $case->setCourtFee(number_format($fees['courtFee'], 2, '.', ''));
        $case->setPlatformFee(number_format($fees['platformFee'], 2, '.', ''));
        $case->setTotalFee(number_format($fees['totalFee'], 2, '.', ''));
        $this->em->flush();
    }

    public function submit(LegalCase $case): void
    {
        $this->calculateFees($case);

        $courtPayment = new Payment();
        $courtPayment->setLegalCase($case);
        $courtPayment->setUser($case->getUser());
        $courtPayment->setAmount($case->getCourtFee());
        $courtPayment->setPaymentType(PaymentType::TAXA_JUDICIARA);
        $courtPayment->setStatus(PaymentStatus::PENDING);
        $this->em->persist($courtPayment);

        $platformPayment = new Payment();
        $platformPayment->setLegalCase($case);
        $platformPayment->setUser($case->getUser());
        $platformPayment->setAmount($case->getPlatformFee());
        $platformPayment->setPaymentType(PaymentType::COMISION_PLATFORMA);
        $platformPayment->setStatus(PaymentStatus::PENDING);
        $this->em->persist($platformPayment);

        $this->workflowService->apply($case, 'submit');
        $case->setSubmittedAt(new \DateTimeImmutable());

        $this->pdfGenerator->generateCasePdf($case);

        $this->em->flush();
    }
}
