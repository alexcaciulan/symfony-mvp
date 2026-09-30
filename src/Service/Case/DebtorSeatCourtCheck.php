<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\Entity\Court;
use App\Entity\LegalCase;
use App\Repository\AuditLogRepository;
use App\Service\Court\CompetentCourtResolver;

/**
 * The debtor of a case is a company shared across the lawyer's cases, so it can
 * be corrected in the library after the case was opened. Two things then matter
 * before the petition is generated: the court was decided from the office the
 * debtor had at creation, and the somatie went out with the identity it had
 * then. This tells the lawyer about both, so the petition is generated knowingly.
 */
final class DebtorSeatCourtCheck
{
    public function __construct(
        private readonly CompetentCourtResolver $resolver,
        private readonly AuditLogRepository $auditLogs,
    ) {}

    /**
     * The court the debtor's current office points to, when it is a definite
     * court other than the case's; null when they agree or the office does not
     * single out a court.
     */
    public function courtNowPointedTo(LegalCase $case): ?Court
    {
        $court = $case->getCourt();
        $debtor = $case->getPrimaryDebtor();
        if ($court === null || $debtor === null) {
            return null;
        }

        $seatCourt = $this->resolver->courtForSeat($court->getType(), $debtor->getAddressCounty(), $debtor->getAddressLocality());
        if ($seatCourt === null || $seatCourt === $court
            || ($seatCourt->getId() !== null && $seatCourt->getId() === $court->getId())) {
            return null;
        }

        return $seatCourt;
    }

    /**
     * The debtor's identity fields changed since the somatie was generated,
     * field to [value at the somatie, current value].
     *
     * @return array<string, array{?string, ?string}>
     */
    public function changesSinceSummons(LegalCase $case): array
    {
        if ($case->getPaymentNoticeDate() === null || $case->getId() === null) {
            return [];
        }
        // The notice date has no time of day, so a change made earlier that
        // same day would pass for a later one; the generation time is exact.
        // The first generation is the reference: which regeneration reached
        // the debtor is not known, so a change after the first is reported.
        $since = $this->auditLogs->findFirstSummonsGeneratedAt($case) ?? $case->getPaymentNoticeDate();

        $changes = [];
        foreach ($this->auditLogs->findDebtorChangesSince($case, $since) as $entry) {
            foreach ($entry->getNewData() ?? [] as $field => $value) {
                $before = array_key_exists($field, $changes) ? $changes[$field][0] : ($entry->getOldData()[$field] ?? null);
                $changes[$field] = [$before, $value];
            }
        }

        return array_filter($changes, static fn (array $pair): bool => $pair[0] !== $pair[1]);
    }
}
