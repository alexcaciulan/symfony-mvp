<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\LegalCaseRepository;
use App\Repository\LegalDeadlineRepository;
use App\Service\Wizard\CaseDraftListBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        Request $request,
        LegalCaseRepository $legalCaseRepository,
        LegalDeadlineRepository $legalDeadlineRepository,
        CaseDraftListBuilder $draftList,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $activeCount = $legalCaseRepository->countActiveByUser($user);
        $recentCases = $legalCaseRepository->findRecentByUser($user, 5);
        $kpis = [
            'active_count' => $activeCount,
            'deadlines_7d' => $legalDeadlineRepository->countUpcomingByUser($user, 7),
            'overdue' => $legalDeadlineRepository->countOverdueByUser($user),
            'amount_active' => $legalCaseRepository->sumActiveAmountByUser($user),
        ];
        $upcomingDeadlines = $legalDeadlineRepository->findUpcomingByUser($user, 7, 10);

        return $this->render('dashboard/index.html.twig', [
            'recent_cases' => $recentCases,
            'has_cases' => $recentCases !== [],
            'kpis' => $kpis,
            'upcoming_deadlines' => $upcomingDeadlines,
            'drafts' => $draftList->forUser($user, $request->getSession()),
        ]);
    }
}
