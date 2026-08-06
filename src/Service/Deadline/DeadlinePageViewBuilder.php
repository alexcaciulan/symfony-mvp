<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\User;
use App\Repository\LegalDeadlineRepository;
use Psr\Clock\ClockInterface;

/**
 * Assembles the deadlines page in one pass: the agenda buckets, the risk bar
 * counters, the blocked cases and the long-horizon prescriptions, each row already
 * carrying the buttons resolved for it.
 *
 * The whole page is server rendered from here, so the risk bar is complete before
 * any fetch and the controller stays a router.
 */
final class DeadlinePageViewBuilder
{
    public function __construct(
        private readonly DeadlineAgendaService $agendaService,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly DeadlineBlockageFinder $blockageFinder,
        private readonly DeadlineBlockageActionResolver $blockageActionResolver,
        private readonly DeadlineRowActionResolver $actionResolver,
        private readonly DeadlineCloseConfirmationResolver $closeConfirmationResolver,
        private readonly ClockInterface $clock,
    ) {}

    public function build(User $user, ?DeadlineAgendaFilter $filter = null): DeadlinePageView
    {
        $filter ??= DeadlineAgendaFilter::none();

        $groups = array_map(
            fn (DeadlineAgendaGroup $group): DeadlineGroupView => new DeadlineGroupView($group->key, $this->toRows($group->items)),
            $this->agendaService->buildAgenda($user, $filter),
        );

        // Found whatever the filter is, because the counter has to keep describing the
        // whole account even while the zone itself is hidden by the selection.
        $blockages = $this->blockageFinder->find($user);

        $buckets = $this->deadlineRepository->countAgendaBuckets($user);
        $counters = [
            'overdue' => $buckets['overdue'],
            'today' => $buckets['today'],
            'fatal30' => $buckets['fatal30'],
            // Counts cases, not deadlines. The reasons cover disjoint case statuses, so
            // one blockage is one case; see DeadlineBlockageReason.
            'blocked' => \count($blockages),
        ];

        return new DeadlinePageView(
            groups: $groups,
            counters: $counters,
            blockages: $filter->includesBlockages() ? $this->toBlockageRows($blockages) : [],
            longHorizonPrescriptions: $this->toRows($this->agendaService->buildLongHorizonPrescriptions($user, filter: $filter)),
            windowEnd: $this->windowEnd(),
            filter: $filter,
        );
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
     * @return list<DeadlineRowView>
     */
    private function toRows(array $items): array
    {
        return array_map(
            fn (DeadlineAgendaItem $item): DeadlineRowView => new DeadlineRowView(
                $item,
                $this->actionResolver->resolve($item),
                $this->closeConfirmationResolver->resolve($item),
            ),
            $items,
        );
    }

    /** Last day the agenda covers, the same horizon the buckets are built on. */
    private function windowEnd(): \DateTimeImmutable
    {
        $today = new \DateTimeImmutable($this->clock->now()->format('Y-m-d'));

        return $today->modify('+' . DeadlineAgendaService::HORIZON_DAYS . ' days');
    }
}
