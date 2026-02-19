<?php

namespace App\Controller\Case;

use App\DTO\Case\Step2ClaimantData;
use App\DTO\Case\Step3DefendantsData;
use App\Entity\LegalCase;
use App\Form\Case\Step1CourtType;
use App\Form\Case\Step2ClaimantType;
use App\Form\Case\Step3DefendantType;
use App\Form\Case\Step4ClaimType;
use App\Repository\CourtRepository;
use App\Repository\LegalCaseRepository;
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
    ) {}

    #[Route('/new', name: 'case_new', methods: ['GET'])]
    public function new(): Response
    {
        $legalCase = new LegalCase();
        $legalCase->setUser($this->getUser());
        $legalCase->setStatus('draft');
        $legalCase->setCurrentStep(1);

        $this->em->persist($legalCase);
        $this->em->flush();

        return $this->redirectToRoute('case_step', ['id' => $legalCase->getId(), 'step' => 1]);
    }

    #[Route('/{id}/step/{step}', name: 'case_step', requirements: ['id' => '\d+', 'step' => '[1-6]'], methods: ['GET', 'POST'])]
    public function step(Request $request, int $id, int $step): Response
    {
        $legalCase = $this->loadAndAuthorize($id, $step);

        $form = $this->createStepForm($step, $legalCase);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->saveStepData($step, $legalCase, $form->getData());
            $legalCase->setCurrentStep(max($legalCase->getCurrentStep(), $step + 1));
            $this->em->flush();

            if ($step < self::TOTAL_STEPS) {
                return $this->redirectToRoute('case_step', ['id' => $id, 'step' => $step + 1]);
            }
        }

        return $this->render('case/wizard.html.twig', [
            'form' => $form,
            'legalCase' => $legalCase,
            'step' => $step,
            'totalSteps' => self::TOTAL_STEPS,
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

        if ($legalCase->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($legalCase->getStatus() !== 'draft') {
            throw $this->createAccessDeniedException('wizard.error.not_draft');
        }

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
}
