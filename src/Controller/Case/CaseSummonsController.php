<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\DTO\Validation\AdmissibilityIssue;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\SubscriptionSlotConsumptionOutcome;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Billing\SubscriptionService;
use App\Service\Case\AccessoryComputabilityChecker;
use App\Service\Case\CaseAccessoryService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Case\OverviewContextBuilder;
use App\Service\Deadline\AgendaResponseFactory;
use App\Service\Document\PaymentNoticeGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Generating the summons. The route also serves the global agenda, where a limitation
 * row on a case still in the amiable stage offers this act as its primary button.
 * Which of the two is acting is told by the `_context` field the agenda posts; without
 * it every answer is the one the case page has always received.
 */
final class CaseSummonsController extends AbstractController
{
    private const C4_MODAL_ID = 'hs-modal-c4-summons';

    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly PaymentNoticeGeneratorService $paymentNoticeGenerator,
        private readonly CaseWorkflowService $workflowService,
        private readonly AuditLogService $auditLogService,
        private readonly SubscriptionService $subscriptionService,
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly AgendaResponseFactory $agendaResponses,
        private readonly EntityManagerInterface $em,
        private readonly CaseAccessoryService $caseAccessories,
        private readonly AccessoryComputabilityChecker $accessoryChecker,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/case/{id}/summons/generate', name: 'case_summons_generate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function generate(int $id, Request $request, RateLimiterFactory $summonsGenerationLimiter): Response
    {
        $case = $this->legalCaseRepository->find($id);

        if (!$case || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        if (!$this->isCsrfTokenValid('generate_summons_' . $id, $request->getPayload()->getString('_token'))) {
            return $this->respond($request, $case, false, 'error', 'case_overview.summons.flash_error_csrf', null, null);
        }

        if ($case->getStatus() !== CaseStatus::AMIABIL) {
            return $this->respond($request, $case, false, 'error', 'case_overview.summons.flash_error_wrong_status', null, null);
        }

        if ($case->getDebtors()->isEmpty()) {
            return $this->respond($request, $case, false, 'error', 'case_overview.summons.flash_error_no_debtor', null, null);
        }

        // A summons that names the interest without its sum is no notice of the
        // amount owed, so it waits until every position the platform computes
        // can be computed. Checked at the date the summons would carry. Claim
        // data is frozen once the case is saved (stamp duty, court and
        // admissibility were derived from it), so the way out is the wizard,
        // which blocks the same defects and lets the position be excluded.
        $notComputable = $this->accessoryChecker->issues($case, $case->accessoryReferenceDate(new \DateTimeImmutable()));
        if ($notComputable !== []) {
            return $this->respond($request, $case, false, 'error', 'case_overview.summons.flash_error_accessory_not_computable', null, null, $notComputable);
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            $limiter = $summonsGenerationLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume()->isAccepted()) {
                return $this->respond($request, $case, false, 'warning', 'rate_limit.summons_generation', null, null);
            }
        }

        // Paywall: activating a case (trimite_somatie) consumes a subscription
        // slot. Blocking outcomes (no/expired/exhausted-trial subscription) are
        // side-effect-free; route the lawyer to the subscription page instead.
        $consumption = $this->subscriptionService->consumeCaseSlot($case);
        if (!$consumption->allowsCaseActivation) {
            $this->addFlash('warning', 'subscription.paywall.blocked');

            return $this->redirectToRoute('app_subscription');
        }

        $this->em->wrapInTransaction(function () use ($case): void {
            // Stored first, so the date printed on the notice is the one the
            // payment order application later computes accessories to.
            $case->setPaymentNoticeDate(new \DateTime());
            $accessoryChange = $this->caseAccessories->refreshStoredTotal($case);
            $document = $this->paymentNoticeGenerator->generate($case);

            $this->workflowService->apply($case, 'trimite_somatie');

            $this->em->flush();

            $this->auditLogService->log(
                action: 'summons_generated',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'documentId' => $document->getId(),
                    'caseNumber' => $case->getCaseNumber(),
                    'paymentNoticeDate' => $case->getPaymentNoticeDate()?->format('Y-m-d'),
                    'calculatedInterest' => $accessoryChange,
                ],
                category: AuditLogService::CATEGORY_SUMMONS_GENERATED,
            );
        });

        $overageToast = SubscriptionSlotConsumptionOutcome::OVERAGE_INVOICE_CREATED === $consumption->outcome
            ? 'subscription.paywall.overage'
            : null;

        // Open the C4 communication memento modal right after the summons is generated.
        return $this->respond($request, $case, true, 'success', 'case_overview.summons.flash_success', self::C4_MODAL_ID, $overageToast);
    }

    /**
     * Turbo Stream (in-place, status regions refreshed) for Turbo clients, redirect
     * + flash otherwise. `$openModalId` surfaces the C4 memento on success.
     *
     * When the act came from the global agenda the answer describes that page
     * instead: `case-hero`, `panel-documente` and the rest of the case regions do not
     * exist there, so the standard stream would swap nothing at all and the lawyer
     * would be left with no sign that the summons had been generated. The C4 memento
     * is not carried over, having nothing to open there: the rebuilt agenda already
     * shows the debtor's term with recording that communication date as its own
     * primary act.
     *
     * @param list<AdmissibilityIssue> $toastIssues listed under the toast, one line each
     */
    private function respond(
        Request $request,
        LegalCase $case,
        bool $updateRegions,
        string $toastVariant,
        string $toastKey,
        ?string $openModalId,
        ?string $extraToastKey,
        array $toastIssues = [],
    ): Response {
        $toastDetails = array_map(
            fn (AdmissibilityIssue $issue): string => $this->translator->trans($issue->messageKey, $issue->params),
            $toastIssues,
        );

        if ($this->agendaResponses->isAgendaRequest($request)) {
            if ($this->agendaResponses->wantsTurboStream($request)) {
                $user = $this->getUser();
                if (!$user instanceof User) {
                    throw $this->createAccessDeniedException();
                }

                return $this->agendaResponses->stream($request, $user, $toastVariant, $toastKey, null, $extraToastKey, $toastDetails);
            }

            $this->flashToast($toastVariant, $toastKey, $toastIssues);
            if ($extraToastKey !== null) {
                $this->addFlash('warning', $extraToastKey);
            }

            return $this->agendaResponses->redirect($request);
        }

        if (str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
            $context = $updateRegions ? $this->contextBuilder->build($case) : ['case' => $case];
            $context['update_regions'] = $updateRegions;
            $context['toast_variant'] = $toastVariant;
            $context['toast_key'] = $toastKey;
            $context['open_modal_id'] = $openModalId;
            $context['close_modal_id'] = null;
            $context['extra_toast_key'] = $extraToastKey;
            $context['toast_details'] = $toastDetails;

            return new Response(
                $this->renderView('case/overview/_documents_generate_turbo_stream.html.twig', $context),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'],
            );
        }

        $this->flashToast($toastVariant, $toastKey, $toastIssues);
        if ($openModalId === self::C4_MODAL_ID) {
            $this->addFlash('show_c4_modal', '1');
        }
        if ($extraToastKey !== null) {
            $this->addFlash('warning', $extraToastKey);
        }

        return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
    }

    /**
     * A message with lines under it goes through the toast flash, the one that
     * renders details; a plain one keeps the flash it always used.
     *
     * @param list<AdmissibilityIssue> $issues
     */
    private function flashToast(string $variant, string $key, array $issues): void
    {
        if ($issues === []) {
            $this->addFlash($variant, $key);

            return;
        }

        $this->addFlash('toast.' . $variant, [
            'key' => $key,
            'details' => array_map(
                static fn (AdmissibilityIssue $issue): array => ['key' => $issue->messageKey, 'params' => $issue->params],
                $issues,
            ),
        ]);
    }
}
