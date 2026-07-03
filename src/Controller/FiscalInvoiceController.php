<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FiscalInvoice;
use App\Entity\User;
use App\Repository\FiscalInvoiceRepository;
use App\Security\Voter\FiscalInvoiceVoter;
use App\Service\Billing\EInvoicing\EInvoicingProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lawyer-facing fiscal invoices: list, detail and PDF download. Stays under
 * `/subscription` so it inherits IS_AUTHENTICATED_FULLY from security.yaml.
 */
#[Route('/subscription/fiscal-invoices')]
class FiscalInvoiceController extends AbstractController
{
    /** Trusted hosts a provider PDF URL may point to (open-redirect guard). */
    private const TRUSTED_PDF_HOSTS = ['www.oblio.eu', 'oblio.eu', 'ws.smartbill.ro'];


    public function __construct(
        private readonly FiscalInvoiceRepository $fiscalInvoices,
        private readonly EInvoicingProviderInterface $provider,
    ) {}

    #[Route('', name: 'app_fiscal_invoices', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('fiscal_invoice/index.html.twig', [
            'invoices' => $this->fiscalInvoices->findByUser($user),
        ]);
    }

    #[Route('/{id}', name: 'app_fiscal_invoice_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(FiscalInvoice $invoice): Response
    {
        $this->denyAccessUnlessGranted(FiscalInvoiceVoter::VIEW, $invoice);

        return $this->render('fiscal_invoice/show.html.twig', [
            'invoice' => $invoice,
        ]);
    }

    #[Route('/{id}/pdf', name: 'app_fiscal_invoice_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(FiscalInvoice $invoice): Response
    {
        $this->denyAccessUnlessGranted(FiscalInvoiceVoter::DOWNLOAD, $invoice);

        // A bad provider toggle (AppSetting) would make the resolver throw; keep
        // it a 404 rather than a 500 for the lawyer.
        try {
            $pdf = $this->provider->getPdf($invoice);
        } catch (\RuntimeException $e) {
            throw $this->createNotFoundException('PDF provider unavailable.');
        }

        if (null === $pdf) {
            // No bytes from the provider: fall back to its hosted URL, but only if
            // it points to a trusted provider host (open-redirect guard).
            $url = $invoice->getPdfUrl();
            if (null !== $url && in_array(parse_url($url, PHP_URL_HOST), self::TRUSTED_PDF_HOSTS, true)) {
                return $this->redirect($url);
            }

            throw $this->createNotFoundException('PDF not available yet.');
        }

        $filename = sprintf('factura-%s-%s.pdf', $invoice->getSeries() ?? 'draft', $invoice->getNumber() ?? (string) $invoice->getId());

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        ]);
    }
}
