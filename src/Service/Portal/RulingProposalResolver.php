<?php

declare(strict_types=1);

namespace App\Service\Portal;

use App\DTO\Portal\RulingProposal;
use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Enum\PortalEventType;
use App\Service\Case\CaseWorkflowService;

/**
 * Maps a portal-detected ruling (the "Tip soluție" field) to the workflow
 * transition it most likely calls for, as an ADVISORY suggestion only. The
 * lawyer always confirms before it is applied (CPC art. 1024: a wrong
 * transition would start the 10-day annulment window on erroneous data).
 *
 * Shared by {@see MonitoringEventApplier} (audit-logs the proposal at detection)
 * and the case overview (surfaces it as a confirmation card).
 */
final class RulingProposalResolver
{
    /** Suggested transitions that have a confirmation modal in the overview. */
    private const MODAL_BY_TRANSITION = [
        'emite_ordonanta' => '#hs-modal-issue-ruling',
        'respinge' => '#hs-modal-reject',
        'admite_cerere_anulare' => '#hs-modal-reject',
        'respinge_cerere_anulare' => '#hs-modal-annulment-rejected',
        'respinge_cerere_anulare_executare' => '#hs-modal-annulment-rejected',
    ];

    public function __construct(
        private readonly CaseWorkflowService $workflowService,
    ) {}

    /**
     * Classifies PRIMARILY on the `solutie` field ("Tip soluție"), a controlled
     * vocabulary of the portal ("Admite cererea" / "Respinge cererea" etc.) that
     * reflects the operative ruling. The free-text `solutieSumar` is only a
     * fallback when the type is missing: its body often mentions a secondary
     * "respinge"/"admite" that would flip a substring-based classification.
     */
    public function suggestedTransition(LegalCase $case, CourtPortalEvent $event): ?string
    {
        $tip = $this->normalize($event->getSolutie() ?? '');
        $text = $tip !== '' ? $tip : $this->normalize($event->getSolutieSumar() ?? '');

        // IN_ANULARE: "anulează" appears ONLY when the cerere în anulare is
        // ADMITTED ("Anulează ordonanța de plată..."), which dissolves the OP.
        // Map it to ADMITE_CERERE_ANULARE, never to a rejection (CPC art. 1024).
        if ($case->getStatus() === CaseStatus::IN_ANULARE) {
            if (str_contains($text, 'admite') || str_contains($text, 'anuleaz')) {
                return CaseTransition::ADMITE_CERERE_ANULARE->value;
            }
            if (str_contains($text, 'respinge') || str_contains($text, 'respins')) {
                return CaseTransition::RESPINGE_CERERE_ANULARE->value;
            }

            return null;
        }

        // EXECUTARE: enforcement was started while the annulment was still pending
        // (CPC art. 1021). Resolving it must stay reachable: ADMIS dissolves the
        // title (-> RESPINSA), RESPINS leaves the title standing and enforcement
        // continues (self-loop on EXECUTARE).
        if ($case->getStatus() === CaseStatus::EXECUTARE) {
            if (str_contains($text, 'admite') || str_contains($text, 'anuleaz')) {
                return CaseTransition::ADMITE_CERERE_ANULARE->value;
            }
            if (str_contains($text, 'respinge') || str_contains($text, 'respins')) {
                return CaseTransition::RESPINGE_CERERE_ANULARE_EXECUTARE->value;
            }

            return null;
        }

        // Main OP phase. "admite" (incl. "admite în parte") = OP issued;
        // "respinge"/"anulează" = petition rejected.
        if (str_contains($text, 'admite')) {
            return CaseTransition::EMITE_ORDONANTA->value;
        }

        if (str_contains($text, 'respinge') || str_contains($text, 'respins') || str_contains($text, 'anuleaz')) {
            return CaseTransition::RESPINGE->value;
        }

        return null;
    }

    /**
     * The most recent ruling event whose suggested transition is currently
     * applicable and has a confirmation modal, or null. `$events` is expected
     * ordered most-recent-first (as `CourtPortalEventRepository::findByLegalCase`).
     *
     * @param CourtPortalEvent[] $events
     */
    public function actionableProposal(LegalCase $case, array $events): ?RulingProposal
    {
        foreach ($events as $event) {
            if (!in_array($event->getEventType(), [PortalEventType::HEARING_COMPLETED, PortalEventType::RULING_ISSUED], true)) {
                continue;
            }

            $transition = $this->suggestedTransition($case, $event);
            if ($transition === null || !isset(self::MODAL_BY_TRANSITION[$transition])) {
                continue;
            }

            if (!$this->workflowService->can($case, $transition)) {
                continue;
            }

            $prefillDate = $event->getEventDate() !== null
                ? \DateTimeImmutable::createFromInterface($event->getEventDate())
                : null;

            return new RulingProposal($event, $transition, self::MODAL_BY_TRANSITION[$transition], $prefillDate);
        }

        return null;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);

        return strtr($text, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
    }
}
