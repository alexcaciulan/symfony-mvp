<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Enum\FilingEvidenceStatus;
use App\Repository\FilingSubmissionRepository;
use App\Service\Filing\FilingSubmissionService;
use App\Service\Filing\FilingTransitionPermit;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Keeps the email filing and the other ways a case moves from contradicting each
 * other, on every path (lawyer, portal, admin): while a submission holds the case
 * only the platform's own acceptance moves it to CERERE_DEPUSA, and registering
 * the case stops the emails still waiting.
 */
final class FilingWorkflowInterlockSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly FilingSubmissionRepository $submissions,
        private readonly FilingTransitionPermit $permit,
        private readonly FilingSubmissionService $submissionService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.legal_case.guard.depune_cerere' => 'guardManualFiling',
            'workflow.legal_case.guard.anuleaza_depunere_nelivrata' => 'guardUndeliveredFiling',
            'workflow.legal_case.completed.inregistreaza_dosar' => 'onRegistered',
        ];
    }

    public function guardManualFiling(GuardEvent $event): void
    {
        $case = $event->getSubject();
        if (!$case instanceof LegalCase || $case->getId() === null) {
            return;
        }

        $active = $this->submissions->findActiveForCase($case);
        if ($active !== null && !$this->permit->allows($active->getId())) {
            $event->setBlocked(true, 'filing.send.flash_manual_refused_active');
        }
    }

    /**
     * Only the platform undoes a filing, for the submission it names, and never on a
     * case that shows the petition arrived (court number, declared date, confirmed
     * portal match).
     */
    public function guardUndeliveredFiling(GuardEvent $event): void
    {
        $case = $event->getSubject();
        if (!$case instanceof LegalCase || $case->getId() === null) {
            $event->setBlocked(true);

            return;
        }

        $latest = $this->submissions->findLatestForCase($case);
        if ($latest === null || !$this->permit->allows($latest->getId())) {
            $event->setBlocked(true, 'filing.undelivered.not_platform');

            return;
        }
        if (!self::canUndoFiling($case, $latest)) {
            $event->setBlocked(true, 'filing.undelivered.case_advanced');
        }
    }

    /**
     * Nothing says the petition reached the court: no court number, no declared
     * date, no confirmed portal match, and on the submission no receipt from the
     * court mailbox (automatic or uploaded) and no read receipt. Any of those
     * contradicts a later delivery report, which then goes to the lawyer's check.
     */
    public static function canUndoFiling(LegalCase $case, ?FilingSubmission $submission = null): bool
    {
        if ($submission !== null && (
            in_array($submission->getEvidenceStatus(), [FilingEvidenceStatus::RECEIPT_RECEIVED, FilingEvidenceStatus::RECEIPT_MANUAL], true)
            || $submission->getReadReceiptAt() !== null
        )) {
            return false;
        }

        return $case->getCourtCaseNumber() === null
            && $case->getFiledAt() === null
            && !$case->hasAcceptedPortalMatch();
    }

    public function onRegistered(CompletedEvent $event): void
    {
        $case = $event->getSubject();
        if ($case instanceof LegalCase) {
            $this->submissionService->cancelRemainingOnRegistration($case);
        }
    }
}
