<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Wizard\CaseDraftListBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class CasesController extends AbstractController
{
    #[Route('/cases', name: 'app_cases')]
    public function index(Request $request, #[CurrentUser] User $user, CaseDraftListBuilder $draftList): Response
    {
        return $this->render('cases/index.html.twig', [
            'drafts' => $draftList->forUser($user, $request->getSession()),
        ]);
    }
}
