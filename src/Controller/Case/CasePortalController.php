<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Enum\PortalCaseMatchSource;
use App\Enum\StampDutyStatus;
use App\Form\Case\PortalActivateType;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Case\OverviewContextBuilder;
use App\Service\Portal\CaseMonitoringService;
use App\Service\Portal\Dto\PortalCaseSuggestion;
use App\Service\Portal\PartyNameNormalizer;
use App\Service\Portal\PortalCaseMatcher;
use App\Service\Portal\PortalJustClient;
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

        // With the case registered, the stamp duty can be paid in this dosar: say
        // so now. Not when the activation left the case where it was.
        $flash = $case->getStatus() === CaseStatus::DOSAR_INREGISTRAT && $case->getStampDutyStatus() === StampDutyStatus::NEACHITATA
            ? 'case_overview.portal.flash_activated_pay_stamp_duty'
            : 'case_overview.portal.flash_activated';

        return $this->respondPortal($request, $case, true, 'success', $flash);
    }

    /**
     * Persists the number + registers the dosar (transactional + audited), then
     * runs an immediate portal check. The check is best-effort: a portal outage
     * must not fail the activation.
     */
    private function applyActivation(LegalCase $case, string $courtCaseNumber, ?string $previousNumber): void
    {
        $this->em->wrapInTransaction(function () use ($case, $courtCaseNumber, $previousNumber): void {
            $case->setCourtCaseNumber($courtCaseNumber, $this->currentUser());
            $case->setPortalMonitoringActive(true);
            $this->em->flush();

            // An ECRIS number means the court has the request, so entering it registers
            // the dosar. Guarded with can(): on a later re-activation the case is already
            // registered and only the fields change.
            //
            // `filedAt` is deliberately left as it is. A dosar on the portal proves the
            // request was filed but says nothing about when, and this field is the
            // lawyer's declaration of the filing date, not our guess. The audit entry
            // below records that the case reached DOSAR_INREGISTRAT without a confirmed
            // filing, which is what a reader would otherwise find inexplicable.
            $filingWasConfirmed = $case->getFiledAt() !== null;
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
                    'filingWasConfirmed' => $filingWasConfirmed,
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
     * The proposed court case as the portal shows it (parties, object, stage), so
     * the lawyer can tell it is theirs before confirming. Read on demand, never
     * stored; only the proposed number is looked up.
     */
    #[Route('/case/{id}/portal/proposal', name: 'case_portal_proposal_details', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function proposalDetails(int $id, PortalJustClient $portalClient, RateLimiterFactory $portalSearchLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        $number = $case->getPortalProposedNumber();
        $portalCode = $case->getCourt()?->getPortalCode();
        if ($number === null || $portalCode === null || $portalCode === '') {
            return $this->renderProposalCard($case, 'not_found');
        }

        $user = $this->getUser();
        if ($user !== null && !$portalSearchLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            return $this->renderProposalCard($case, 'rate_limited');
        }

        try {
            $found = $portalClient->searchByCaseNumber($number, $portalCode);
        } catch (\Throwable $e) {
            $this->logger->error('Portal lookup of the proposed case failed', ['caseId' => $case->getId(), 'exception' => $e->getMessage()]);

            return $this->renderProposalCard($case, 'error');
        }

        $dosar = null;
        foreach ($found as $candidate) {
            if (($candidate['numar'] ?? null) === $number) {
                $dosar = $candidate;
                break;
            }
        }
        if ($dosar === null) {
            return $this->renderProposalCard($case, 'not_found');
        }

        $ours = array_values(array_filter([
            $case->getCreditor()?->getName(),
            ...array_map(static fn ($d): ?string => $d->getName(), $case->getDebtors()->toArray()),
        ], static fn (?string $name): bool => $name !== null && $name !== ''));
        $parties = array_map(static fn (array $parte): array => [
            'nume' => (string) ($parte['nume'] ?? ''),
            'calitate' => $parte['calitateParte'] ?? null,
            'ours' => array_filter($ours, static fn (string $name): bool => PartyNameNormalizer::matches($name, (string) ($parte['nume'] ?? ''))) !== [],
        ], $dosar['parti'] ?? []);

        return $this->renderProposalCard($case, 'ok', $dosar, $parties);
    }

    /**
     * @param array<string, mixed>|null $dosar
     * @param list<array{nume: string, calitate: ?string, ours: bool}> $parties
     */
    private function renderProposalCard(LegalCase $case, string $state, ?array $dosar = null, array $parties = []): Response
    {
        return $this->render('case/overview/_portal_proposed_card.html.twig', [
            'case' => $case,
            'state' => $state,
            'dosar' => $dosar,
            'parties' => $parties,
        ]);
    }

    /**
     * The lawyer sets aside a dosar the search proposed. The number is kept as set
     * aside, so neither search proposes it again.
     */
    #[Route('/case/{id}/portal/proposal/dismiss', name: 'case_portal_proposal_dismiss', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function dismissProposal(int $id, Request $request): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);
        if (!$this->isCsrfTokenValid('portal_proposal_dismiss_' . $id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $proposed = $case->dismissPortalProposal($this->currentUser());
        if ($proposed !== null) {
            $this->auditLogService->log(
                action: 'portal_proposal_dismissed',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                oldData: ['portalProposedNumber' => $proposed],
                newData: ['caseNumber' => $case->getCaseNumber()],
                category: AuditLogService::CATEGORY_PORTAL_MONITORING,
            );
            $this->em->flush();
        }

        $this->addFlash('success', 'case_overview.portal.flash_proposal_dismissed');

        return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
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

        // A clear match is kept as the case's proposal, so the case page keeps
        // pointing to it after this list is gone.
        $best = $suggestions[0] ?? null;
        if ($best !== null && $best->isHighConfidence && $case->proposePortalMatch($best->numar, PortalCaseMatchSource::MANUAL)) {
            $this->em->flush();
        }

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

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
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
