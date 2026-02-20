<?php

namespace App\Controller\Case;

use App\DTO\Case\Step2ClaimantData;
use App\DTO\Case\Step3DefendantsData;
use App\DTO\Case\Step5EvidenceData;
use App\Entity\LegalCase;
use App\Entity\Payment;
use App\Enum\PaymentStatus;
use App\Enum\PaymentType;
use App\Form\Case\DocumentUploadType;
use App\Form\Case\Step1CourtType;
use App\Form\Case\Step2ClaimantType;
use App\Form\Case\Step3DefendantType;
use App\Form\Case\Step4ClaimType;
use App\Form\Case\Step5EvidenceType;
use App\Form\Case\Step6ConfirmationType;
use App\Repository\CourtRepository;
use App\Repository\LegalCaseRepository;
use App\Service\Case\CaseWorkflowService;
use App\Service\Case\TaxCalculatorService;
use App\Service\Document\PdfGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/case')]
class CaseWizardController extends AbstractController
{
    private const TOTAL_STEPS = 6;

    public function __construct(
        private EntityManagerInterface $em,
        private LegalCaseRepository $legalCaseRepository,
        private CourtRepository $courtRepository,
        private TaxCalculatorService $taxCalculator,
        private CaseWorkflowService $workflowService,
        private PdfGeneratorService $pdfGenerator,
    ) {}

    #[Route('/new', name: 'case_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->createForm(Step1CourtType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $legalCase = new LegalCase();
            $legalCase->setUser($this->getUser());
            $legalCase->setStatus('draft');
            $legalCase->setCurrentStep(2);

            $this->saveStep1($legalCase, $form->getData());
            $this->em->persist($legalCase);
            $this->em->flush();

            return $this->redirectToRoute('case_step', ['id' => $legalCase->getId(), 'step' => 2]);
        }

        return $this->render('case/wizard.html.twig', [
            'form' => $form,
            'legalCase' => null,
            'step' => 1,
            'totalSteps' => self::TOTAL_STEPS,
        ]);
    }

    #[Route('/{id}/step/{step}', name: 'case_step', requirements: ['id' => '\d+', 'step' => '[1-6]'], methods: ['GET', 'POST'])]
    public function step(Request $request, int $id, int $step): Response
    {
        $legalCase = $this->loadAndAuthorize($id, $step);

        // Calculate fees on step 6 display
        if ($step === 6 && $request->isMethod('GET')) {
            $this->calculateAndSaveFees($legalCase);
        }

        $form = $this->createStepForm($step, $legalCase);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($step === 6) {
                $this->submitCase($legalCase);
                $this->addFlash('success', 'wizard.step6.submit_success');

                return $this->redirectToRoute('case_payment', ['id' => $id]);
            }

            $this->saveStepData($step, $legalCase, $form->getData());
            $legalCase->setCurrentStep(max($legalCase->getCurrentStep(), $step + 1));
            $this->em->flush();

            return $this->redirectToRoute('case_step', ['id' => $id, 'step' => $step + 1]);
        }

        return $this->render('case/wizard.html.twig', [
            'form' => $form,
            'legalCase' => $legalCase,
            'step' => $step,
            'totalSteps' => self::TOTAL_STEPS,
        ]);
    }

    #[Route('/{id}', name: 'case_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id): Response
    {
        $legalCase = $this->legalCaseRepository->find($id);

        if (!$legalCase || $legalCase->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted('CASE_VIEW', $legalCase);

        // Draft cases should use the wizard
        if ($legalCase->getStatus() === 'draft') {
            return $this->redirectToRoute('case_step', ['id' => $id, 'step' => $legalCase->getCurrentStep()]);
        }

        $canUpload = $this->isGranted('CASE_UPLOAD', $legalCase);
        $uploadForm = $canUpload ? $this->createForm(DocumentUploadType::class) : null;

        return $this->render('case/view.html.twig', [
            'legalCase' => $legalCase,
            'canUpload' => $canUpload,
            'uploadForm' => $uploadForm,
        ]);
    }

    #[Route('/courts-by-county/{county}', name: 'case_courts_by_county', methods: ['GET'])]
    public function courtsByCounty(string $county): JsonResponse
    {
        $courts = $this->courtRepository->findActiveByCounty($county);

        $data = array_map(fn($court) => [
            'id' => $court->getId(),
            'name' => $court->getName(),
        ], $courts);

        return $this->json($data);
    }

    private function loadAndAuthorize(int $id, int $step): LegalCase
    {
        $legalCase = $this->legalCaseRepository->find($id);

        if (!$legalCase || $legalCase->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted('CASE_EDIT', $legalCase);

        if ($step > $legalCase->getCurrentStep()) {
            throw $this->createNotFoundException('wizard.error.step_not_reached');
        }

        return $legalCase;
    }

    private function createStepForm(int $step, LegalCase $legalCase): \Symfony\Component\Form\FormInterface
    {
        return match ($step) {
            1 => $this->createForm(Step1CourtType::class, [
                'county' => $legalCase->getCounty(),
                'court' => $legalCase->getCourt(),
            ]),
            2 => $this->createForm(Step2ClaimantType::class,
                Step2ClaimantData::fromLegalCase($legalCase, $this->getUser())
            ),
            3 => $this->createForm(Step3DefendantType::class,
                Step3DefendantsData::fromLegalCase($legalCase)
            ),
            4 => $this->createForm(Step4ClaimType::class, [
                'claimAmount' => $legalCase->getClaimAmount(),
                'claimDescription' => $legalCase->getClaimDescription(),
                'dueDate' => $legalCase->getDueDate(),
                'legalBasis' => $legalCase->getLegalBasis(),
                'interestType' => $legalCase->getInterestType(),
                'interestRate' => $legalCase->getInterestRate(),
                'interestStartDate' => $legalCase->getInterestStartDate(),
                'requestCourtCosts' => $legalCase->isRequestCourtCosts(),
            ]),
            5 => $this->createForm(Step5EvidenceType::class,
                Step5EvidenceData::fromLegalCase($legalCase)
            ),
            6 => $this->createForm(Step6ConfirmationType::class),
            default => throw $this->createNotFoundException(),
        };
    }

    private function saveStepData(int $step, LegalCase $legalCase, mixed $data): void
    {
        match ($step) {
            1 => $this->saveStep1($legalCase, $data),
            2 => $this->saveStep2($legalCase, $data),
            3 => $this->saveStep3($legalCase, $data),
            4 => $this->saveStep4($legalCase, $data),
            5 => $this->saveStep5($legalCase, $data),
            default => null,
        };
    }

    private function saveStep1(LegalCase $legalCase, array $data): void
    {
        $legalCase->setCounty($data['county']);
        $legalCase->setCourt($data['court']);
    }

    private function saveStep2(LegalCase $legalCase, Step2ClaimantData $data): void
    {
        $legalCase->setClaimantType($data->type);
        $legalCase->setClaimantData($data->toArray());
        $legalCase->setHasLawyer($data->hasLawyer);
        $legalCase->setLawyerData($data->toLawyerArray());
    }

    private function saveStep3(LegalCase $legalCase, Step3DefendantsData $data): void
    {
        $legalCase->setDefendants($data->toArray());
    }

    private function saveStep4(LegalCase $legalCase, array $data): void
    {
        $legalCase->setClaimAmount($data['claimAmount']);
        $legalCase->setClaimDescription($data['claimDescription']);
        $legalCase->setDueDate($data['dueDate'] ?? null);
        $legalCase->setLegalBasis($data['legalBasis'] ?? null);
        $legalCase->setInterestType($data['interestType'] ?? 'none');
        $legalCase->setInterestRate($data['interestRate'] ?? null);
        $legalCase->setInterestStartDate($data['interestStartDate'] ?? null);
        $legalCase->setRequestCourtCosts($data['requestCourtCosts'] ?? false);
    }

    private function saveStep5(LegalCase $legalCase, Step5EvidenceData $data): void
    {
        $legalCase->setEvidenceDescription($data->evidenceDescription);
        $legalCase->setHasWitnesses($data->hasWitnesses);
        $legalCase->setWitnesses($data->toWitnessesArray());
        $legalCase->setRequestOralDebate($data->requestOralDebate);
    }

    private function calculateAndSaveFees(LegalCase $legalCase): void
    {
        $claimAmount = (float) $legalCase->getClaimAmount();

        if ($claimAmount <= 0) {
            return;
        }

        $fees = $this->taxCalculator->calculate($claimAmount);
        $legalCase->setCourtFee(number_format($fees['courtFee'], 2, '.', ''));
        $legalCase->setPlatformFee(number_format($fees['platformFee'], 2, '.', ''));
        $legalCase->setTotalFee(number_format($fees['totalFee'], 2, '.', ''));
        $this->em->flush();
    }

    private function submitCase(LegalCase $legalCase): void
    {
        // Ensure fees are calculated
        $this->calculateAndSaveFees($legalCase);

        // Create payment for court fee
        $courtPayment = new Payment();
        $courtPayment->setLegalCase($legalCase);
        $courtPayment->setUser($legalCase->getUser());
        $courtPayment->setAmount($legalCase->getCourtFee());
        $courtPayment->setPaymentType(PaymentType::TAXA_JUDICIARA);
        $courtPayment->setStatus(PaymentStatus::PENDING);
        $this->em->persist($courtPayment);

        // Create payment for platform fee
        $platformPayment = new Payment();
        $platformPayment->setLegalCase($legalCase);
        $platformPayment->setUser($legalCase->getUser());
        $platformPayment->setAmount($legalCase->getPlatformFee());
        $platformPayment->setPaymentType(PaymentType::COMISION_PLATFORMA);
        $platformPayment->setStatus(PaymentStatus::PENDING);
        $this->em->persist($platformPayment);

        // Transition via workflow (draft → pending_payment)
        $this->workflowService->apply($legalCase, 'submit');
        $legalCase->setSubmittedAt(new \DateTimeImmutable());

        // Generate PDF (Cerere cu Valoare Redusă)
        $this->pdfGenerator->generateCasePdf($legalCase);

        $this->em->flush();
    }
}
