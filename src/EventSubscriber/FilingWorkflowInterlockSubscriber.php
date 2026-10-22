<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\LegalCase;
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

    public function onRegistered(CompletedEvent $event): void
    {
        $case = $event->getSubject();
        if ($case instanceof LegalCase) {
            $this->submissionService->cancelRemainingOnRegistration($case);
        }
    }
}
