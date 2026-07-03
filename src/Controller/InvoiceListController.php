<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Facturi" page: a shell that mounts the invoices DataTable (filters +
 * pagination). Data is served by the generic /api/table/invoices endpoint.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class InvoiceListController extends AbstractController
{
    #[Route('/invoices', name: 'app_invoices')]
    public function index(): Response
    {
        return $this->render('invoices/index.html.twig');
    }
}
