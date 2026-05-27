<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\Case\OverviewContextBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CaseOverviewController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $cases,
        private readonly OverviewContextBuilder $contextBuilder,
    ) {}

    #[Route('/case/{id}', name: 'case_overview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(Request $request, int $id): Response
    {
        $case = $this->cases->findWithOverviewRelations($id);
        if ($case === null) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(CaseVoter::VIEW, $case);

        // One-time „Următorul pas" badge on the „Generează cerere OP" CTA,
        // emitted by CaseWizardController right after submit. The flash bag
        // is read-and-consume so reloading the page clears the badge.
        $justCreated = $request->getSession()->getFlashBag()->get('case_just_created') !== [];

        return $this->render(
            'case/overview.html.twig',
            $this->contextBuilder->build($case, ['just_created' => $justCreated]),
        );
    }
}
