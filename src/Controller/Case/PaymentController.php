<?php

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Repository\LegalCaseRepository;
use App\Service\Payment\PaymentProcessingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/case')]
class PaymentController extends AbstractController
{
    public function __construct(
        private LegalCaseRepository $legalCaseRepository,
        private PaymentProcessingService $paymentProcessingService,
    ) {}

    #[Route('/{id}/payment', name: 'case_payment', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function payment(int $id): Response
    {
        $legalCase = $this->loadCase($id);
        $this->denyAccessUnlessGranted('CASE_VIEW', $legalCase);

        if ($legalCase->getStatus() !== 'pending_payment') {
            $flashKey = $legalCase->getStatus() === 'draft' ? 'payment.not_pending' : 'payment.already_paid';
            $this->addFlash('warning', $flashKey);

            return $this->redirectToRoute('case_view', ['id' => $id]);
        }

        return $this->render('case/payment.html.twig', [
            'legalCase' => $legalCase,
        ]);
    }

    #[Route('/{id}/payment/process', name: 'case_payment_process', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function process(Request $request, int $id): Response
    {
        $legalCase = $this->loadCase($id);
        $this->denyAccessUnlessGranted('CASE_VIEW', $legalCase);

        if (!$this->isCsrfTokenValid('payment-process-' . $id, $request->getPayload()->getString('_token'))) {
            $this->addFlash('danger', 'payment.invalid_csrf');

            return $this->redirectToRoute('case_payment', ['id' => $id]);
        }

        if ($legalCase->getStatus() !== 'pending_payment') {
            $this->addFlash('warning', 'payment.already_paid');

            return $this->redirectToRoute('case_view', ['id' => $id]);
        }

        $this->paymentProcessingService->processPayment($legalCase);

        $this->addFlash('success', 'payment.success');

        return $this->redirectToRoute('case_view', ['id' => $id]);
    }

    private function loadCase(int $id): LegalCase
    {
        $legalCase = $this->legalCaseRepository->find($id);

        if (!$legalCase || $legalCase->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $legalCase;
    }
}
