<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\CourtRepository;
use App\Repository\CreditorRepository;
use App\Service\Address\RomanianAddressFormatter;
use App\Service\Company\AnafAddressMapper;
use App\Service\Company\AnafLookupException;
use App\Service\Company\AnafLookupService;
use App\Service\Court\AdministrativeUnitResolver;
use App\Util\PiiMasker;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Internal JSON endpoint used by the `party-anaf-lookup` Stimulus controller. Hit
 * when the user presses the explicit "sync from ANAF" button on the debtor in
 * Step 2 or the creditor in Step 1, to populate name/address plus the structured
 * county/locality. Those two feed different decisions: the competent court for the
 * debtor, the stamp-duty payment UAT for the creditor (OUG 80/2013 art. 40 alin. 1).
 *
 * Session-authenticated (firewall `main` covers `/api`); rate-limited via the
 * existing `company_lookup` factory (10/hour/user). The endpoint never proxies
 * arbitrary callers: `IsGranted` enforces a logged-in user.
 */
#[Route('/api', name: 'api_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class LookupController extends AbstractController
{
    #[Route(
        '/anaf-lookup/{cui}',
        name: 'anaf_lookup',
        requirements: ['cui' => '(RO)?\d{2,10}'],
        methods: ['GET'],
    )]
    public function anafLookup(
        string $cui,
        #[CurrentUser] User $user,
        AnafLookupService $anafLookupService,
        AnafAddressMapper $anafAddressMapper,
        RomanianAddressFormatter $addressFormatter,
        AdministrativeUnitResolver $administrativeUnitResolver,
        RateLimiterFactory $companyLookupLimiter,
        LoggerInterface $logger,
    ): JsonResponse {
        $normalized = strtoupper(preg_replace('/\s+/', '', $cui) ?? '');
        $digits = preg_replace('/^RO/', '', $normalized) ?? '';

        if (!PiiMasker::isValidCui($digits)) {
            return new JsonResponse(
                ['error' => 'exception.anaf.cui_invalid'],
                JsonResponse::HTTP_NOT_FOUND,
            );
        }

        $limiter = $companyLookupLimiter->create($user->getUserIdentifier());
        if (!$limiter->consume(1)->isAccepted()) {
            return new JsonResponse(
                ['error' => 'exception.anaf.rate_limited'],
                JsonResponse::HTTP_TOO_MANY_REQUESTS,
            );
        }

        try {
            $data = $anafLookupService->lookupByCui($digits);
        } catch (AnafLookupException $e) {
            $logger->warning('ANAF lookup failed for CUI {cui}: {error}', [
                'cui' => $digits,
                'error' => $e->getMessage(),
            ]);

            // Always return the generic i18n key — `$e->getMessage()` today
            // is `exception.anaf.unavailable` / `exception.anaf.not_found` (safe
            // i18n keys) but the contract is not type-enforced. Hard-coding
            // here avoids leaking arbitrary internal error strings to the
            // client if a future `throw` in AnafLookupService changes the
            // message shape.
            return new JsonResponse(
                ['error' => 'exception.anaf.unavailable'],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $mapping = $anafAddressMapper->map($data);
        $location = $administrativeUnitResolver->resolve($data['county'], $data['city']);

        return new JsonResponse([
            'companyName' => $data['companyName'],
            'cui' => $data['cui'],
            // Street level only. The locality and the county are returned
            // separately and must not be repeated here.
            'address' => $addressFormatter->format($mapping->parts),
            // Structured county + locality feed the competent-court resolver at
            // step 4 (judecatorie/tribunal teritorial) and the town hall that
            // collects the stamp duty, and they are what the generated documents
            // print, so they carry the SIRUTA spelling rather than ANAF's.
            'county' => $location->countyName,
            'locality' => $location->localityName,
            // Two conditions the lawyer has to act on, rather than discover in
            // court: ANAF holds a different fiscal domicile, so the unit details
            // could not be trusted; and ANAF has no postal code on file.
            'fiscalDomicileDiffers' => $mapping->fiscalDomicileDiffers,
            'postalCodeMissing' => $mapping->parts->postalCode === null,
            'anafStatus' => $data['stare'],
            'anafCheckedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Remote source for the cases-list court filter (Tom Select `load` callback).
     * Scoped to courts the current user has cases in, so the dropdown never lists
     * courts that would produce zero filter hits. The `q` query is matched against
     * the court name (case-insensitive LIKE), capped at 20 results.
     */
    #[Route('/courts-lookup', name: 'courts_lookup', methods: ['GET'])]
    public function courtsLookup(
        Request $request,
        #[CurrentUser] User $user,
        CourtRepository $courtRepository,
    ): JsonResponse {
        $query = trim((string) $request->query->get('q', ''));

        return new JsonResponse($courtRepository->findForUserAutocomplete($user, $query));
    }

    /**
     * Remote source for the cases-list creditor filter (Tom Select `load`).
     * Scoped to creditors the current user has cases with, so the dropdown never
     * lists creditors that would yield zero filter hits. The `q` query is matched
     * against the creditor name (case-insensitive LIKE), capped at 20 results.
     */
    #[Route('/creditors-lookup', name: 'creditors_lookup', methods: ['GET'])]
    public function creditorsLookup(
        Request $request,
        #[CurrentUser] User $user,
        CreditorRepository $creditorRepository,
    ): JsonResponse {
        $query = trim((string) $request->query->get('q', ''));

        return new JsonResponse($creditorRepository->findForUserAutocomplete($user, $query));
    }

    /**
     * Remote source for the wizard step-4 competent-court picker (Tom Select
     * `load` callback). Searches ALL active courts by name (NOT scoped to the
     * user's cases): a brand-new case may need any court. The `q` query is
     * matched case-insensitively, capped at 20 results.
     */
    #[Route('/courts-all-lookup', name: 'courts_all_lookup', methods: ['GET'])]
    public function courtsAllLookup(
        Request $request,
        CourtRepository $courtRepository,
    ): JsonResponse {
        $query = trim((string) $request->query->get('q', ''));

        return new JsonResponse($courtRepository->searchActiveByName($query));
    }
}
