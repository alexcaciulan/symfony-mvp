<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalDeadline;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Service\Case\CaseNextStepFacts;
use App\Service\Case\CaseNextStepResolver;
use Psr\Clock\ClockInterface;

/**
 * Translates the next step of a case into the suggestion of an agenda row. The rule itself
 * lives in {@see CaseNextStepResolver}, so a row and the case header cannot disagree;
 * anything the header reports as blocked by something else leaves the row silent.
 *
 * Only the terms that the next procedural act stops or follows carry a suggestion. A
 * limitation term that has run out carries none: a summons can no longer interrupt it.
 */
final class DeadlineNextStepResolver
{
    private const HERO = 'case_overview.hero.next_step.';
    private const OWN = 'deadlines.next_step.sentence.';

    public function __construct(
        private readonly CaseNextStepResolver $caseResolver,
        private readonly DeadlineService $deadlineService,
        private readonly ClockInterface $clock,
    ) {}

    public function resolve(DeadlineAgendaItem $item, CaseNextStepFacts $facts): ?DeadlineNextStep
    {
        $deadline = $item->deadline;
        if ($deadline->isCompleted() || !$this->suggestsOn($item)) {
            return null;
        }

        $case = $deadline->getLegalCase();

        // Checked first: the case rule walks lazy collections on the later stages, and
        // only these three stages ever suggest anything.
        if (!\in_array($case->getStatus(), [CaseStatus::AMIABIL, CaseStatus::SOMATIE_TRIMISA, CaseStatus::CERERE_GENERATA], true)) {
            return null;
        }

        $step = $this->caseResolver->resolve($case, $facts);

        return match ($case->getStatus()) {
            CaseStatus::AMIABIL => $step->blockedBy === null
                ? new DeadlineNextStep(DeadlineNextStep::SUMMONS, self::HERO . 'AMIABIL')
                : null,
            CaseStatus::SOMATIE_TRIMISA => $this->afterSummons($deadline, $step->blockedBy),
            CaseStatus::CERERE_GENERATA => match (true) {
                $step->blockedBy !== null => null,
                $step->filingLink => new DeadlineNextStep(DeadlineNextStep::FILING_PACKAGE, self::HERO . 'filing_ready'),
                default => new DeadlineNextStep(DeadlineNextStep::FILING, self::HERO . 'CERERE_GENERATA'),
            },
            default => null,
        };
    }

    private function suggestsOn(DeadlineAgendaItem $item): bool
    {
        return match ($item->deadline->getType()) {
            DeadlineType::PRESCRIPTIE => $item->daysRemaining >= 0,
            DeadlineType::DEPUNERE_CERERE, DeadlineType::RASPUNS_SOMATIE => true,
            default => false,
        };
    }

    private function afterSummons(LegalDeadline $deadline, ?string $blockedBy): ?DeadlineNextStep
    {
        return match ($blockedBy) {
            CaseNextStepResolver::SUMMONS_BLOCKED => new DeadlineNextStep(DeadlineNextStep::COMMUNICATION_DATE, self::OWN . 'summons_blocked'),
            CaseNextStepResolver::PROOF_BLOCKED => new DeadlineNextStep(DeadlineNextStep::PROOF, self::OWN . 'proof_blocked'),
            null => new DeadlineNextStep(
                $this->deadlineService->isPaymentTermExpired($deadline->getLegalCase(), $this->today()) ? DeadlineNextStep::PAYMENT_ORDER : null,
                self::HERO . 'SOMATIE_TRIMISA',
            ),
            default => null,
        };
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->clock->now()->format('Y-m-d'));
    }
}
