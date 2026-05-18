<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Repository\DocumentRepository;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Document\CaseFilesPackager;
use App\Service\Document\OpisGeneratorService;
use App\Service\Document\PaymentOrderRequestGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pas 5.2 — generarea cererii de OP + opis + descărcare ZIP pentru depunere
 * la registratura instanței. Workflow trigger: `depune_cerere` (SOMATIE_TRIMISA
 * → CERERE_DEPUSA). Idempotency strict — o singură cerere OP per dosar.
 */
final class CasePaymentOrderController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly PaymentOrderRequestGeneratorService $paymentOrderGenerator,
        private readonly OpisGeneratorService $opisGenerator,
        private readonly CaseFilesPackager $caseFilesPackager,
        private readonly CaseWorkflowService $workflowService,
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/case/{id}/payment-order/generate', name: 'case_payment_order_generate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function generate(int $id, Request $request, RateLimiterFactory $paymentOrderGenerationLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        if (!$this->isCsrfTokenValid('generate_payment_order_' . $id, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'case_overview.payment_order.flash_error_csrf');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        if ($case->getStatus() !== CaseStatus::SOMATIE_TRIMISA) {
            $this->addFlash('error', 'case_overview.payment_order.flash_error_wrong_status');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        if ($case->getCourt() === null) {
            $this->addFlash('error', 'case_overview.payment_order.flash_error_no_court');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        if ($this->hasDocument($case, DocumentType::CERERE_OP)) {
            $this->addFlash('warning', 'case_overview.payment_order.flash_error_already_generated');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $user = $this->getUser();
        if ($user !== null) {
            $limiter = $paymentOrderGenerationLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('warning', 'rate_limit.payment_order_generation');

                return $this->redirectToRoute('case_overview', ['id' => $id]);
            }
        }

        $this->em->wrapInTransaction(function () use ($case): void {
            $paymentOrder = $this->paymentOrderGenerator->generate($case);
            $opis = $this->opisGenerator->generate($case);
            $this->em->flush();

            $this->workflowService->apply($case, 'depune_cerere');

            $this->auditLogService->log(
                action: 'payment_order_generated',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'paymentOrderDocumentId' => $paymentOrder->getId(),
                    'opisDocumentId' => $opis->getId(),
                ],
                category: AuditLogService::CATEGORY_PAYMENT_ORDER_GENERATED,
            );
            $this->em->flush();
        });

        $this->addFlash('success', 'case_overview.payment_order.flash_success');

        return $this->redirectToRoute('case_overview', ['id' => $id]);
    }

    #[Route('/case/{id}/zip-package/download', name: 'case_zip_package_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadZip(int $id): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::VIEW, $case);

        // ZIP-ul presupune că Document-urile generate există (cerere OP + opis +
        // somația). Dacă lipsește unul, redirectăm cu flash error — avocatul
        // trebuie să apese „Generează cerere OP" mai întâi.
        if (!$this->hasDocument($case, DocumentType::CERERE_OP)
            || !$this->hasDocument($case, DocumentType::OPIS)
            || !$this->hasDocument($case, DocumentType::SOMATIE)) {
            $this->addFlash('error', 'case_overview.zip_package.flash_error_incomplete');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $zipPath = $this->caseFilesPackager->package($case);

        $response = new BinaryFileResponse($zipPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('Pachet_%s.zip', $case->getCaseNumber()),
        );
        $response->headers->set('Content-Type', 'application/zip');

        return $response;
    }

    private function findOrThrow(int $id): LegalCase
    {
        $case = $this->legalCaseRepository->find($id);
        if (!$case instanceof LegalCase || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $case;
    }

    private function hasDocument(LegalCase $case, DocumentType $type): bool
    {
        return $this->documentRepository->findOneBy([
            'legalCase' => $case,
            'documentType' => $type,
        ]) !== null;
    }
}
