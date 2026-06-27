<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Enum\CaseTransition;
use App\Form\Case\PortalActivateType;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Case\OverviewContextBuilder;
use App\Service\Portal\CaseMonitoringService;
use App\Service\Portal\Dto\PortalCaseSuggestion;
use App\Service\Portal\PortalCaseMatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pas 6.1 — activarea monitorizării portal.just.ro și verificarea manuală din
 * tab-ul „Activitate Portal". Voter `TRANSITION` (ownership-only): activarea
 * poate declanșa `inregistreaza_dosar`, iar `EDIT` ar bloca activarea din
 * statusuri ne-editabile (DOSAR_INREGISTRAT+).
 */
final class CasePortalController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly CaseWorkflowService $workflowService,
        private readonly CaseMonitoringService $monitoringService,
        private readonly PortalCaseMatcher $caseMatcher,
        private readonly AuditLogService $auditLogService,
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/case/{id}/portal/activate', name: 'case_portal_activate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function activate(int $id, Request $request): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        $form = $this->createForm(PortalActivateType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $firstError = null;
            foreach ($form->getErrors(true) as $error) {
                $firstError = $error;
                break;
            }
            $toastKey = $firstError?->getMessage() ?? 'case_overview.portal.flash_activate_error';

            return $this->respondPortal($request, $case, false, 'error', $toastKey);
        }

        $courtCaseNumber = (string) $form->getData()['courtCaseNumber'];
        $previousNumber = $case->getCourtCaseNumber();

        // Once real portal activity exists, the number is load-bearing: changing it
        // to a different dosar would mix two cases' histories. Correcting it (with
        // proper cleanup) is a deferred feature, so we refuse the change here.
        // Setting/re-confirming the same number, or first activation, stays allowed.
        if ($previousNumber !== null && $previousNumber !== $courtCaseNumber && $this->hasPortalActivity($case)) {
            $this->auditLogService->log(
                action: 'portal_case_number_change_rejected',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                oldData: ['courtCaseNumber' => $previousNumber],
                newData: ['attemptedNumber' => $courtCaseNumber, 'reason' => 'portal_activity_lock'],
                category: AuditLogService::CATEGORY_PORTAL_MONITORING,
            );
            $this->em->flush();

            return $this->respondPortal($request, $case, false, 'error', 'case_overview.portal.flash_change_locked');
        }

        $this->applyActivation($case, $courtCaseNumber, $previousNumber);

        return $this->respondPortal($request, $case, true, 'success', 'case_overview.portal.flash_activated');
    }

    /**
     * Persists the number + registers the dosar (transactional + audited), then
     * runs an immediate portal check. The check is best-effort: a portal outage
     * must not fail the activation.
     */
    private function applyActivation(LegalCase $case, string $courtCaseNumber, ?string $previousNumber): void
    {
        $this->em->wrapInTransaction(function () use ($case, $courtCaseNumber, $previousNumber): void {
            $case->setCourtCaseNumber($courtCaseNumber);
            $case->setPortalMonitoringActive(true);
            $this->em->flush();

            // Spec Faza 3: introducerea nr. dosar din CERERE_DEPUSA înregistrează
            // dosarul. Gardat cu can() — dacă dosarul e deja înregistrat
            // (activare ulterioară), doar setăm câmpurile.
            if ($this->workflowService->can($case, CaseTransition::INREGISTREAZA_DOSAR->value)) {
                $this->workflowService->apply($case, CaseTransition::INREGISTREAZA_DOSAR->value);
            }

            $this->auditLogService->log(
                action: 'portal_activated',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                oldData: ['courtCaseNumber' => $previousNumber],
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'courtCaseNumber' => $courtCaseNumber,
                    'status' => $case->getStatus()->value,
                ],
                category: AuditLogService::CATEGORY_PORTAL_MONITORING,
            );
            $this->em->flush();
        });

        try {
            $this->monitoringService->monitorCase($case);
        } catch (\Throwable $e) {
            $this->logger->error('Immediate portal check after activation failed', [
                'caseId' => $case->getId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Auto-discover the case on portal.just.ro from the data we already hold
     * (parties + competent court), so the lawyer does not have to leave the
     * platform to find and copy the ECRIS number. Renders ranked suggestions into
     * a Turbo Frame; each suggestion posts the chosen number to `activate`.
     */
    #[Route('/case/{id}/portal/discover', name: 'case_portal_discover', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function discover(int $id, Request $request, RateLimiterFactory $portalSearchLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        if (!$this->isCsrfTokenValid('portal_discover_' . $id, $request->getPayload()->getString('_token'))) {
            return $this->renderDiscover($case, [], 'error');
        }

        // Discovery is a setup step. Once real activity exists, the number is
        // locked (changing it is a deferred correction), so we do not re-search.
        if ($this->hasPortalActivity($case)) {
            return $this->renderDiscover($case, [], 'locked');
        }

        $court = $case->getCourt();
        if ($court === null || ($court->getPortalCode() ?? '') === '') {
            return $this->renderDiscover($case, [], 'no_portal_code');
        }

        $user = $this->getUser();
        if ($user !== null && !$portalSearchLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            return $this->renderDiscover($case, [], 'rate_limited');
        }

        try {
            $suggestions = $this->caseMatcher->findCandidates($case);
        } catch (\Throwable $e) {
            $this->logger->error('Portal case discovery failed', [
                'caseId' => $case->getId(),
                'exception' => $e->getMessage(),
            ]);
            $this->logDiscovery($case, null);

            return $this->renderDiscover($case, [], 'error');
        }

        $this->logDiscovery($case, count($suggestions));

        return $this->renderDiscover($case, $suggestions, $suggestions === [] ? 'empty' : 'ok');
    }

    /**
     * Records a discovery search in the audit trail (success and failure alike),
     * so the case history reflects every portal lookup the lawyer initiated.
     * `$resultCount` is null when the search errored.
     */
    private function logDiscovery(LegalCase $case, ?int $resultCount): void
    {
        $this->auditLogService->log(
            action: 'portal_discovery_searched',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            newData: [
                'caseNumber' => $case->getCaseNumber(),
                'resultCount' => $resultCount,
                'status' => $resultCount === null ? 'error' : 'ok',
            ],
            category: AuditLogService::CATEGORY_PORTAL_MONITORING,
        );
        $this->em->flush();
    }

    /**
     * @param PortalCaseSuggestion[] $suggestions
     */
    private function renderDiscover(LegalCase $case, array $suggestions, string $state): Response
    {
        return $this->render('case/overview/_portal_discover_results.html.twig', [
            'case' => $case,
            'suggestions' => $suggestions,
            'state' => $state,
        ]);
    }

    #[Route('/case/{id}/portal/check-now', name: 'case_portal_check_now', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function checkNow(int $id, Request $request, RateLimiterFactory $portalCheckLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        if (!$this->isCsrfTokenValid('portal_check_' . $id, $request->getPayload()->getString('_token'))) {
            return $this->respondPortal($request, $case, false, 'error', 'case_overview.portal.flash_activate_error');
        }

        if (!$case->isPortalMonitoringActive() || $case->getCourtCaseNumber() === null) {
            return $this->respondPortal($request, $case, false, 'warning', 'case_overview.portal.flash_not_active');
        }

        $user = $this->getUser();
        if ($user !== null && !$portalCheckLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            return $this->respondPortal($request, $case, false, 'warning', 'rate_limit.portal_check');
        }

        try {
            $this->monitoringService->monitorCase($case);
        } catch (\Throwable $e) {
            $this->logger->error('Manual portal check failed', [
                'caseId' => $case->getId(),
                'exception' => $e->getMessage(),
            ]);

            return $this->respondPortal($request, $case, false, 'error', 'case_overview.portal.flash_check_error');
        }

        return $this->respondPortal($request, $case, true, 'success', 'case_overview.portal.flash_check_done');
    }

    private function findOrThrow(int $id): LegalCase
    {
        $case = $this->legalCaseRepository->findWithOverviewRelations($id);
        if (!$case instanceof LegalCase || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $case;
    }

    /**
     * Turbo Stream (in-place, tab preserved) for Turbo clients, redirect + flash
     * otherwise. The toast is rendered into the stream, not the flash bag, so it
     * cannot leak onto a later full-page load.
     */
    private function respondPortal(
        Request $request,
        LegalCase $case,
        bool $updateRegions,
        string $toastVariant,
        string $toastKey,
    ): Response {
        if (str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
            $context = $updateRegions
                ? $this->contextBuilder->build($case)
                : ['case' => $case];
            $context['update_regions'] = $updateRegions;
            $context['toast_variant'] = $toastVariant;
            $context['toast_key'] = $toastKey;

            return new Response(
                $this->renderView('case/overview/_portal_actions_turbo_stream.html.twig', $context),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'],
            );
        }

        $this->addFlash($toastVariant, $toastKey);

        return $this->redirectToRoute('case_overview', ['id' => $case->getId(), 'tab' => 'portal']);
    }

    /**
     * Whether the case already has real portal activity. Past this threshold the
     * court case number is treated as load-bearing (see flash_change_locked).
     * count() on the unloaded collection issues a COUNT without hydrating.
     */
    private function hasPortalActivity(LegalCase $case): bool
    {
        return $case->getPortalEvents()->count() > 0;
    }
}
