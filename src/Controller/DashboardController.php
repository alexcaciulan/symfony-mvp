<?php

namespace App\Controller;

use App\Repository\LegalCaseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard/cases', name: 'dashboard_cases')]
    public function cases(LegalCaseRepository $legalCaseRepository): Response
    {
        $cases = $legalCaseRepository->findByUser($this->getUser());

        return $this->render('dashboard/cases.html.twig', [
            'cases' => $cases,
        ]);
    }
}
