<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalDeadline;
use App\Enum\DeadlineConsequence;
use App\Enum\DeadlineType;

/**
 * Fixed mapping from deadline type to the legal consequence of missing it. The
 * mapping lives in PHP rather than in the database because it follows the law, not
 * the data: no user may edit it and it must not drift per case.
 *
 * Nothing here computes a date; the resolver only classifies. Legal date arithmetic
 * stays in {@see DeadlineService}.
 */
final class DeadlineConsequenceResolver
{
    /**
     * Legal basis per type, as stated in the deadline analysis:
     * - TIMBRARE: OUG 80/2013 art. 33 para. 2, the claim is annulled (CPC art. 197)
     * - CERERE_IN_ANULARE: CPC art. 1024 para. 1, forfeiture of the remedy (art. 185 para. 1)
     * - PRESCRIPTIE: NCC art. 2517, the substantive right to sue is extinguished
     * - PRESCRIPTIE_EXECUTARE: CPC art. 706 para. 1, the title loses its enforceability
     * - JUDECATA: the hearing is held even in absence, so only the chance to answer is lost
     * - RASPUNS_SOMATIE: the debtor's own term (CPC art. 1015 para. 1); its expiry is what
     *   unblocks filing the payment order, so it carries no sanction for the creditor
     * - DEPUNERE_CERERE: NCC art. 2540, to which CPC art. 1015 para. 2 refers. Filing
     *   later than six months after the summons was communicated does not bar the
     *   claim by itself, but the interruption the summons produced is deemed never to
     *   have happened, so the limitation period is computed as if the summons had not
     *   been sent and the claim is met with prescription. The loss is the same one
     *   PRESCRIPTIE carries, which is why it is classified with it rather than as
     *   record keeping
     * - OTHER: no legal basis, pure record keeping
     */
    public function resolve(DeadlineType $type): DeadlineConsequence
    {
        return match ($type) {
            DeadlineType::TIMBRARE => DeadlineConsequence::CASE_ANNULMENT,
            DeadlineType::CERERE_IN_ANULARE => DeadlineConsequence::FORFEITURE,
            DeadlineType::PRESCRIPTIE, DeadlineType::PRESCRIPTIE_EXECUTARE, DeadlineType::DEPUNERE_CERERE => DeadlineConsequence::RIGHT_EXTINCTION,
            DeadlineType::JUDECATA => DeadlineConsequence::APPEARANCE,
            DeadlineType::RASPUNS_SOMATIE => DeadlineConsequence::NO_SANCTION,
            DeadlineType::OTHER => DeadlineConsequence::RECORD_KEEPING,
        };
    }

    public function resolveFor(LegalDeadline $deadline): DeadlineConsequence
    {
        return $this->resolve($deadline->getType());
    }

    /** Ordering weight for deadlines falling on the same day; higher means graver. */
    public function severityRank(DeadlineType $type): int
    {
        return $this->resolve($type)->severityRank();
    }

    /** Whether missing this type cannot be undone by an ordinary remedy. */
    public function isIrreversible(DeadlineType $type): bool
    {
        return $this->resolve($type)->isIrreversible();
    }

    /**
     * Every type whose miss is irreversible, derived from the mapping above so no
     * consumer has to restate it. This is the single source for the fatal set: a
     * type added here or a consequence changed above propagates on its own, with
     * no second list to keep in sync.
     *
     * @return list<DeadlineType>
     */
    public function irreversibleTypes(): array
    {
        return array_values(array_filter(
            DeadlineType::cases(),
            fn (DeadlineType $type): bool => $this->isIrreversible($type),
        ));
    }

    /**
     * Every type whose expiry carries no sanction for the creditor, derived from the
     * same mapping. Today this is the debtor's own term: its expiry is not a delay of
     * the lawyer but the event that opens the filing of the request (CPC art.
     * 1015-1016), which is why the agenda keeps these rows out of the overdue section
     * and out of the overdue counters and shows them in a section of their own.
     *
     * @return list<DeadlineType>
     */
    public function unsanctionedTypes(): array
    {
        return array_values(array_filter(
            DeadlineType::cases(),
            fn (DeadlineType $type): bool => $this->resolve($type) === DeadlineConsequence::NO_SANCTION,
        ));
    }
}
