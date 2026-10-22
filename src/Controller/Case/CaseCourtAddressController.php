<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\CourtEmailAddress;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailReportReason;
use App\Repository\CourtEmailAddressRepository;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\Court\CourtEmailActionRefusedException;
use App\Service\Court\CourtEmailAddressService;
use App\Service\Court\CourtFilingAddressResolver;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The addresses of the case's court, seen from the case: confirming one is the
 * lawyer's own declaration, so every change is owner only (no admin bypass). An
 * admin sees the page read-only. Nominal addresses never appear here.
 */
final class CaseCourtAddressController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly CourtEmailAddressRepository $addressRepository,
        private readonly CourtEmailAddressService $addressService,
        private readonly CourtFilingAddressResolver $resolver,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/case/{id}/court-address', name: 'case_court_address', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(int $id): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::VIEW, $case);

        return $this->render('court/address/index.html.twig', $this->context($case));
    }

    #[Route('/case/{id}/court-address/{addressId}/validate', name: 'case_court_address_validate', requirements: ['id' => '\d+', 'addressId' => '\d+'], methods: ['POST'])]
    public function validate(int $id, int $addressId, Request $request, RateLimiterFactory $courtAddressValidationLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);
        $address = $this->findAddress($case, $addressId);

        if ($refusal = $this->guard($request, $case, 'validate', $courtAddressValidationLimiter)) {
            return $refusal;
        }

        try {
            $this->addressService->validate($address, $this->currentUser(), $request->getPayload()->getBoolean('statement_confirmed'), $case);
        } catch (CourtEmailActionRefusedException $e) {
            return $this->respond($request, $case, 'error', $e->messageKey);
        }

        return $this->respond($request, $case, 'success', 'court_address.validation.toast.validated');
    }

    #[Route('/case/{id}/court-address/manual', name: 'case_court_address_manual', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addManual(int $id, Request $request, RateLimiterFactory $courtAddressValidationLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);
        $court = $case->getCourt() ?? throw $this->createNotFoundException();

        if ($refusal = $this->guard($request, $case, 'manual', $courtAddressValidationLimiter)) {
            return $refusal;
        }

        $purpose = CourtEmailPurpose::tryFrom($request->getPayload()->getString('purpose'));
        if ($purpose === null || !in_array($purpose, CourtEmailPurpose::manualChoices(), true)) {
            return $this->respond($request, $case, 'error', 'court_address.validation.error.invalid_purpose');
        }

        try {
            $this->addressService->addManual($court, $request->getPayload()->getString('email'), $purpose, $case);
        } catch (CourtEmailActionRefusedException $e) {
            return $this->respond($request, $case, 'error', $e->messageKey);
        }

        return $this->respond($request, $case, 'success', 'court_address.validation.toast.added');
    }

    #[Route('/case/{id}/court-address/{addressId}/report', name: 'case_court_address_report', requirements: ['id' => '\d+', 'addressId' => '\d+'], methods: ['POST'])]
    public function report(int $id, int $addressId, Request $request, RateLimiterFactory $courtAddressValidationLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);
        $address = $this->findAddress($case, $addressId);

        if ($refusal = $this->guard($request, $case, 'report', $courtAddressValidationLimiter)) {
            return $refusal;
        }

        $reason = CourtEmailReportReason::tryFrom($request->getPayload()->getString('reason'));
        if ($reason === null) {
            return $this->respond($request, $case, 'error', 'court_address.validation.error.invalid_reason');
        }

        try {
            $this->addressService->reportWrong($address, $reason, $case);
        } catch (CourtEmailActionRefusedException $e) {
            return $this->respond($request, $case, 'error', $e->messageKey);
        }

        return $this->respond($request, $case, 'success', 'court_address.validation.toast.reported');
    }

    /** CSRF first, then the rate budget: a forged request must not spend the lawyer's tokens. */
    private function guard(Request $request, LegalCase $case, string $action, RateLimiterFactory $limiter): ?Response
    {
        if (!$this->isCsrfTokenValid('court_address_' . $action . '_' . $case->getId(), $request->getPayload()->getString('_token'))) {
            return $this->respond($request, $case, 'error', 'court_address.validation.error.csrf');
        }
        if (!$limiter->create($this->currentUser()->getUserIdentifier())->consume()->isAccepted()) {
            return $this->respond($request, $case, 'warning', 'court_address.validation.error.rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function context(LegalCase $case): array
    {
        $court = $case->getCourt();

        return [
            'case' => $case,
            'court' => $court,
            'addresses' => $court !== null ? $this->addressRepository->findForCourt($court) : [],
            'recipient' => $this->resolver->resolve($case),
            'is_owner' => $this->isGranted(CaseVoter::FILING_CONTENT, $case),
            'statement' => $this->addressService->statementText(),
            'manual_purposes' => CourtEmailPurpose::manualChoices(),
            'report_reasons' => CourtEmailReportReason::cases(),
            'now' => $this->clock->now(),
        ];
    }

    /**
     * Turbo Stream for Turbo clients (the list is replaced in place, every outcome
     * is a toast); redirect with flash otherwise.
     */
    private function respond(Request $request, LegalCase $case, string $variant, string $key, int $status = Response::HTTP_OK): Response
    {
        if (str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
            return new Response(
                $this->renderView('court/address/_turbo_stream.html.twig', $this->context($case) + [
                    'toast_variant' => $variant,
                    'toast_key' => $key,
                ]),
                $status,
                ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'],
            );
        }

        $this->addFlash($variant, $key);

        return $this->redirectToRoute('case_court_address', ['id' => $case->getId()]);
    }

    /** Scoped to the case's court; a nominal address is treated as absent. */
    private function findAddress(LegalCase $case, int $addressId): CourtEmailAddress
    {
        $court = $case->getCourt();
        $address = $court !== null ? $this->addressRepository->findForCourtById($court, $addressId) : null;
        if ($address === null || $address->isPersonal()) {
            throw $this->createNotFoundException();
        }

        return $address;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function findOrThrow(int $id): LegalCase
    {
        $case = $this->legalCaseRepository->find($id);
        if (!$case instanceof LegalCase || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $case;
    }
}
