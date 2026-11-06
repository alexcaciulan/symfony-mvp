<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Repository\LegalDeadlineRepository;
use App\Service\Case\CaseNextStepFacts;
use Psr\Clock\ClockInterface;

/**
 * Assembles the deadlines page in one pass: the numbers of the whole account, and the rows
 * of the active tab only, each already carrying its controls and its suggestion.
 *
 * The whole page is server rendered from here, so the controller stays a router.
 */
final class DeadlinePageViewBuilder
{
    private const COMPLETED_PAGE_SIZE = 50;

    public function __construct(
        private readonly DeadlineAgendaService $agendaService,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly DeadlineBlockageFinder $blockageFinder,
        private readonly DeadlineBlockageActionResolver $blockageActionResolver,
        private readonly DeadlineRowActionResolver $actionResolver,
        private readonly DeadlineCloseConfirmationResolver $closeConfirmationResolver,
        private readonly DeadlineNextStepResolver $nextStepResolver,
        private readonly CaseNextStepFactsProvider $factsProvider,
        private readonly ClockInterface $clock,
    ) {}

    public function build(User $user, ?DeadlineAgendaFilter $filter = null): DeadlinePageView
    {
        $filter ??= DeadlineAgendaFilter::none();

        // Found whatever the tab is: the box counts them on every tab.
        $blockages = $this->blockageFinder->find($user);
        $buckets = $this->deadlineRepository->countAgendaBuckets($user);
        $prescriptions = $this->deadlineRepository->summarizeLongHorizonPrescriptions($user);
        $completed = $this->deadlineRepository->summarizeCompleted($user);

        $counters = $buckets + [
            // Counts cases, not deadlines. The reasons are mutually exclusive, so one
            // blockage is one case; see DeadlineBlockageReason.
            'blocked' => \count($blockages),
            'prescriptions' => $prescriptions['count'],
            'completed' => $completed['count'],
        ];

        $groups = [];
        if ($filter->isTab(DeadlineAgendaFilter::TAB_TODO)) {
            $agenda = $this->agendaService->buildAgenda($user, $filter);
            $facts = $this->factsFor(array_merge(...array_map(static fn (DeadlineAgendaGroup $g): array => $g->items, $agenda)));
            $groups = array_map(
                fn (DeadlineAgendaGroup $group): DeadlineGroupView => new DeadlineGroupView($group->key, $this->toRows($group->items, $facts)),
                $agenda,
            );
        }

        $limit = self::COMPLETED_PAGE_SIZE * $filter->page;
        $completedRows = $filter->isTab(DeadlineAgendaFilter::TAB_COMPLETED)
            ? $this->agendaService->buildCompleted($user, $limit + 1)
            : [];

        $prescriptionRows = [];
        if ($filter->isTab(DeadlineAgendaFilter::TAB_PRESCRIPTIONS)) {
            $items = $this->agendaService->buildLongHorizonPrescriptions($user);
            $prescriptionRows = $this->toRows($items, $this->factsFor($items));
        }

        return new DeadlinePageView(
            groups: $groups,
            counters: $counters,
            blockages: $filter->isTab(DeadlineAgendaFilter::TAB_BLOCKED) ? $this->toBlockageRows($blockages) : [],
            prescriptions: $prescriptionRows,
            completed: $this->toRows(\array_slice($completedRows, 0, $limit), []),
            hasMoreCompleted: \count($completedRows) > $limit && $filter->canGrow(),
            nextPrescription: $prescriptions['next'],
            lastCompleted: $completed['last'],
            windowEnd: $this->windowEnd(),
            filter: $filter,
        );
    }

    /** One row, for the side panel. */
    public function buildRow(LegalDeadline $deadline): DeadlineRowView
    {
        $item = $this->agendaService->buildItem($deadline);

        return $this->toRow($item, $this->factsFor([$item]));
    }

    /** The blockage of one case of the user, for the side panel; null once it is resolved. */
    public function buildBlockageRow(User $user, int $caseId): ?DeadlineBlockageView
    {
        foreach ($this->blockageFinder->find($user) as $blockage) {
            if ($blockage->legalCase->getId() === $caseId) {
                return new DeadlineBlockageView($blockage, $this->blockageActionResolver->resolve($blockage));
            }
        }

        return null;
    }

    /**
     * @param list<DeadlineBlockage> $blockages
     *
     * @return list<DeadlineBlockageView>
     */
    private function toBlockageRows(array $blockages): array
    {
        return array_map(
            fn (DeadlineBlockage $blockage): DeadlineBlockageView => new DeadlineBlockageView(
                $blockage,
                $this->blockageActionResolver->resolve($blockage),
            ),
            $blockages,
        );
    }

    /**
     * @param list<DeadlineAgendaItem> $items
     *
     * @return array<int, CaseNextStepFacts>
     */
    private function factsFor(array $items): array
    {
        return $this->factsProvider->forCases(array_values(array_map(
            static fn (DeadlineAgendaItem $item): LegalCase => $item->deadline->getLegalCase(),
            $items,
        )));
    }

    /**
     * @param list<DeadlineAgendaItem>      $items
     * @param array<int, CaseNextStepFacts> $facts
     *
     * @return list<DeadlineRowView>
     */
    private function toRows(array $items, array $facts): array
    {
        return array_map(fn (DeadlineAgendaItem $item): DeadlineRowView => $this->toRow($item, $facts), $items);
    }

    /** @param array<int, CaseNextStepFacts> $facts */
    private function toRow(DeadlineAgendaItem $item, array $facts): DeadlineRowView
    {
        $caseFacts = $facts[$item->deadline->getLegalCase()->getId()] ?? null;
        $nextStep = $caseFacts !== null ? $this->nextStepResolver->resolve($item, $caseFacts) : null;

        return new DeadlineRowView(
            $item,
            $this->actionResolver->resolve($item, $nextStep),
            $this->closeConfirmationResolver->resolve($item),
            $nextStep,
        );
    }

    /** Last day the agenda covers, the same horizon the buckets are built on. */
    private function windowEnd(): \DateTimeImmutable
    {
        $today = new \DateTimeImmutable($this->clock->now()->format('Y-m-d'));

        return $today->modify('+' . DeadlineAgendaService::HORIZON_DAYS . ' days');
    }
}
