<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class CasesController extends AbstractController
{
    #[Route('/cases', name: 'app_cases')]
    public function index(): Response
    {
        return $this->render('cases/index.html.twig');
    }
}
