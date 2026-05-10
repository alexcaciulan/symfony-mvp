<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\LegalCaseRepository;
use App\Repository\LegalDeadlineRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard/cases', name: 'dashboard_cases')]
    public function cases(
        LegalCaseRepository $legalCaseRepository,
        LegalDeadlineRepository $legalDeadlineRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $cases = $legalCaseRepository->findByUser($user);
        $kpis = [
            'active_count' => $legalCaseRepository->countActiveByUser($user),
            'deadlines_7d' => $legalDeadlineRepository->countUpcomingByUser($user, 7),
            'amount_active' => $legalCaseRepository->sumActiveAmountByUser($user),
            'portal_events' => 0, // placeholder — wired at Pas 6.x portal monitoring
        ];
        $upcomingDeadlines = $legalDeadlineRepository->findUpcomingByUser($user, 7, 5);

        return $this->render('dashboard/cases.html.twig', [
            'cases' => $cases,
            'kpis' => $kpis,
            'upcoming_deadlines' => $upcomingDeadlines,
            'sidebar_cases_count' => count($cases),
            'sidebar_deadlines_count' => $kpis['deadlines_7d'],
        ]);
    }
}
