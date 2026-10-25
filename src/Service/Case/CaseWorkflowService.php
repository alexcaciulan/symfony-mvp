<?php

namespace App\Service\Case;

use App\Entity\LegalCase;
use Symfony\Component\Workflow\WorkflowInterface;

class CaseWorkflowService
{
    public function __construct(
        private WorkflowInterface $legalCaseStateMachine,
    ) {}

    /**
     * @param array<string, mixed> $context reaches the workflow listeners; `actor`
     *                                      names the user behind a transition applied
     *                                      without a session (a worker)
     */
    public function apply(LegalCase $case, string $transition, array $context = []): void
    {
        $this->legalCaseStateMachine->apply($case, $transition, $context);
    }

    public function can(LegalCase $case, string $transition): bool
    {
        return $this->legalCaseStateMachine->can($case, $transition);
    }

    public function getAvailableTransitions(LegalCase $case): array
    {
        return array_map(
            fn($t) => $t->getName(),
            $this->legalCaseStateMachine->getEnabledTransitions($case)
        );
    }
}
