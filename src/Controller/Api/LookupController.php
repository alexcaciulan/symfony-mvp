<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\CourtRepository;
use App\Repository\CreditorRepository;
use App\Service\Company\AnafLookupException;
use App\Service\Company\AnafLookupService;
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
 * Pas 3.3 — internal JSON endpoint used by the `debtor-anaf-lookup` Stimulus
 * controller. Hit on CUI blur in Step 2 to populate name/address + the
 * `anafStatus` / `anafCheckedAt` hidden fields stored on `Step2DebtorEntry`.
 *
 * Session-authenticated (firewall `main` covers `/api`); rate-limited via the
 * existing `company_lookup` factory (10/hour/user). The endpoint never proxies
 * arbitrary callers — `IsGranted` enforces a logged-in user.
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

        return new JsonResponse([
            'companyName' => $data['companyName'],
            'cui' => $data['cui'],
            'address' => self::composeAddress($data),
            // Structured county + locality feed the competent-court resolver at
            // step 4 (judecatorie/tribunal teritorial). Kept separate from the
            // composed `address` string, which stays the human-readable display.
            'county' => $data['county'],
            'locality' => $data['city'],
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

    /**
     * ANAF returns address parts separately (street/number/city/county). The
     * `Step2DebtorEntry::$address` is a single `TextareaType` — join everything
     * the API gave us into one human-readable line.
     */
    private static function composeAddress(array $data): string
    {
        $parts = array_filter([
            $data['street'] ?? null,
            $data['streetNumber'] ? 'nr. ' . $data['streetNumber'] : null,
            $data['city'] ?? null,
            $data['county'] ?? null,
            $data['postalCode'] ?? null,
        ], static fn (?string $v): bool => $v !== null && $v !== '');

        return implode(', ', $parts);
    }
}
