<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Legal consequence of missing a deadline. It is a property of the law, not of the
 * user, so it is never stored: it is derived from the deadline type by
 * {@see \App\Service\Deadline\DeadlineConsequenceResolver}.
 *
 * Deliberately distinct from {@see DeadlinePriority}, which is derived mechanically
 * from the same type and therefore carries no independent information.
 */
enum DeadlineConsequence: string
{
    /** Stamp duty not paid in time: the claim is annulled (OUG 80/2013 art. 33 para. 2, CPC art. 197). */
    case CASE_ANNULMENT = 'CASE_ANNULMENT';

    /** Annulment request not filed in time: forfeiture of the remedy (CPC art. 1024 para. 1, art. 185 para. 1). */
    case FORFEITURE = 'FORFEITURE';

    /** Limitation period elapsed: the right itself is extinguished (NCC art. 2517, CPC art. 706 para. 1). */
    case RIGHT_EXTINCTION = 'RIGHT_EXTINCTION';

    /** Hearing date: the case is tried even in absence, so the loss is the chance to answer. */
    case APPEARANCE = 'APPEARANCE';

    /** Term belonging to the debtor: nothing is lost when it expires, it unblocks the next step. */
    case NO_SANCTION = 'NO_SANCTION';

    /** Operational reminder with no legal basis and no sanction. */
    case RECORD_KEEPING = 'RECORD_KEEPING';

    public function label(): string
    {
        return 'enum.deadline_consequence.' . $this->value;
    }

    /**
     * Whether missing the deadline cannot be undone by an ordinary remedy. Used to
     * separate the terms that end a case from those that merely cost an opportunity.
     */
    public function isIrreversible(): bool
    {
        return match ($this) {
            self::CASE_ANNULMENT, self::FORFEITURE, self::RIGHT_EXTINCTION => true,
            self::APPEARANCE, self::NO_SANCTION, self::RECORD_KEEPING => false,
        };
    }

    /**
     * Severity rank used to order deadlines falling on the same day: the higher the
     * number, the graver the consequence.
     *
     * The three irreversible ones are ranked by how much remedy is left. Extinction
     * of the right has none. Forfeiture can only be lifted by proving well founded
     * reasons, within 15 days of the impediment ceasing (CPC art. 186). Annulment of
     * the claim ranks below it because the order carries no res judicata, so the
     * claim can simply be filed again.
     *
     * Deliberately not cited here: OUG 80/2013 art. 39. That article is the
     * reexamination of the stamp duty AMOUNT, filed within 3 days of being told what
     * is owed, not a remedy against an annulment already ordered for non-payment.
     * The ordering rests on res judicata alone, which still needs the confirmation
     * recorded in the analysis document.
     */
    public function severityRank(): int
    {
        return match ($this) {
            self::RIGHT_EXTINCTION => 50,
            self::FORFEITURE => 40,
            self::CASE_ANNULMENT => 30,
            self::APPEARANCE => 20,
            self::RECORD_KEEPING => 10,
            self::NO_SANCTION => 0,
        };
    }
}
