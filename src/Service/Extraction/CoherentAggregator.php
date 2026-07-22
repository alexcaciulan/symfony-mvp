<?php

declare(strict_types=1);

namespace App\Service\Extraction;

use App\DTO\Extraction\AggregatedFields;
use App\DTO\Extraction\ConflictOption;
use App\DTO\Extraction\ConflictResolution;
use App\DTO\Extraction\FieldSource;
use App\DTO\Extraction\PrefillConflict;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Enum\FieldGroup;

/**
 * Picks one document per group of fields and takes the group whole.
 *
 * The problem it exists for: choosing the best value for every field
 * independently lets the name come from the contract and the CUI from the
 * invoice. When those two documents describe different entities, which is
 * ordinary once correspondence with a third party is in the pile, the result is
 * a legal person that does not exist, in a document filed with a court. That is
 * worse than prefilling nothing, because nothing prompts anyone to check it.
 *
 * So: one winner per group, the winner's group taken entire, and gap-filling
 * from other documents only where the winner said nothing and only when the two
 * documents can be shown to be about the same party.
 *
 * Determinism is a requirement, not a nicety. The same documents must produce
 * the same case every time, or two runs of the wizard produce two different
 * filings and nobody can say which one the lawyer reviewed. Every comparison
 * here ends in a total order, with the document id as the final tie-break.
 */
final class CoherentAggregator
{
    /**
     * Below this a field is not confident enough to prefill. Same bar the
     * wizard used before groups existed: anything badged "auto" has to be
     * trustworthy enough for the lawyer to glance and confirm.
     */
    public const MIN_CONFIDENCE = 0.8;

    /**
     * Free prose never says the same thing twice. Two invoices for the same
     * service describe it in wording that differs by diacritics and phrasing,
     * which is a divergence with no legal consequence: raising it would train
     * the lawyer to dismiss the panel that also carries contradicting tax
     * numbers. The winning document still supplies the value.
     */
    private const FREE_TEXT_FIELDS = ['description'];

    /**
     * Above this a reading is evidence about who the party is, even though it
     * is still too weak to be written into the form. The two questions are not
     * the same: a name read at 0.6 must not be prefilled, but it is a name the
     * document states, and a name that does not match is proof of a second
     * party. Below this floor the reading is noise and decides nothing, in
     * either direction.
     */
    public const LEGIBLE_CONFIDENCE = 0.5;

    /**
     * How alike two names must be to accept that two documents without a shared
     * registration number are about the same party. Deliberately below the
     * clustering threshold: filling a gap is a smaller claim than declaring two
     * parties to be one.
     */
    public const FILL_NAME_SIMILARITY = 0.85;

    /**
     * How alike two names must be to merge two candidates into one party. Being
     * wrong here is expensive in one direction only: splitting one debtor in
     * two costs the lawyer two clicks, merging two debtors into one produces a
     * filing against a party that was never identified.
     */
    public const IDENTITY_NAME_SIMILARITY = 0.90;

    /** Weight of the authority rank, which no confidence gap may overturn. */
    private const AUTHORITY_SCALE = 1000;

    /** Weight of the mean confidence over the group. */
    private const CONFIDENCE_SCALE = 10;

    /**
     * Legal forms that distinguish one legal person from another. Two companies
     * of a group are routinely one word apart and differ only here: "ALFA TRANS
     * SRL" and "ALFA TRANS SA" are two taxpayers with two registration numbers,
     * so these are stripped for comparison but never ignored.
     *
     * @var list<string>
     */
    private const CORE_LEGAL_FORMS = ['SRL', 'SRLD', 'SA', 'SNC', 'SCS', 'SCA', 'PFA', 'II', 'IF'];

    /**
     * Forms that say nothing about which company it is. "SC" prefixes half the
     * companies in the country and its presence or absence is typography.
     *
     * @var list<string>
     */
    private const GENERIC_LEGAL_FORMS = ['SC'];

    public function __construct(
        private readonly FieldAuthorityMatrix $authority = new FieldAuthorityMatrix(),
    ) {}

    /**
     * @param list<FieldSource> $sources
     * @param array<string, FieldGroup> $fieldGroups field name to its group
     * @param ConflictScope $scope what the conflicts collected here are about
     * @param ?string $entityKey which subject of that scope, when there are several
     * @param array<string, ConflictResolution> $pins what the lawyer decided,
     *        by field: it outranks every ranking below, and a pin on a document
     *        moves the whole group of fields to that document rather than
     *        copying one value across
     */
    public function aggregate(
        array $sources,
        array $fieldGroups,
        ConflictScope $scope = ConflictScope::CLAIM,
        ?string $entityKey = null,
        array $pins = [],
    ): AggregatedFields {
        if ($sources === []) {
            return new AggregatedFields();
        }

        $values = [];
        $provenance = [];
        $conflicts = [];
        $manual = [];
        $anchor = null;
        /** @var array<string, int> $borrowedIdentity identity field to the document it was completed from */
        $borrowedIdentity = [];

        foreach ($this->fieldsByGroup($fieldGroups) as $groupName => $fields) {
            $group = FieldGroup::from($groupName);
            /** @var array<string, ConflictResolution> $groupPins */
            $groupPins = array_intersect_key($pins, array_flip($fields));
            // Everything about one party has to come from documents about that
            // party. Once identity is settled, a document describing somebody
            // else is out of the running for the address and the bank account
            // too, not merely barred from filling their gaps: a contact group
            // nobody else covers would otherwise be won outright by the wrong
            // company, which is the same chimera one level up.
            $eligible = $anchor === null
                ? $sources
                : array_values(array_filter($sources, fn (FieldSource $s): bool => $this->provesSameParty($anchor, $s)));

            $ranked = $this->rank($eligible, $group, $fields);
            if ($ranked === []) {
                $this->applyPins($groupPins, null, $values, $provenance, $manual);

                continue;
            }

            // Numbered off the ranking as the algorithm produced it, before any
            // pin moves the winner: the option index of a choice made on the
            // previous request has to still mean the same value on this one.
            $groupConflicts = $this->collectConflicts($ranked, $fields, $scope, $entityKey);

            // A chosen document wins its whole group. Taking only the chosen
            // field from it and the rest from the ranking is how a party gets a
            // name from one document and a registration number from another.
            $winner = $this->pinnedWinner($sources, $groupPins) ?? $ranked[0];
            $donors = array_values(array_filter($ranked, static fn (FieldSource $s): bool => $s !== $winner));
            if ($group === FieldGroup::PARTY_IDENTITY) {
                $anchor = $winner;
            }
            foreach ($fields as $field) {
                $value = $winner->trustedValue($field, self::MIN_CONFIDENCE);
                if ($value !== null) {
                    $values[$field] = $value;
                    $provenance[$field] = $winner->documentId;
                }
            }

            // Gap filling. Only fields the winner left empty, only from a
            // document that survives the identity guard, and in the same
            // deterministic order the ranking produced. What the guard demands
            // depends on what is being lent: a value that says who the party is
            // may only come from a document provably about that party, while a
            // claim figure identifies nobody and only has to come from a
            // document that does not contradict.
            foreach ($donors as $donor) {
                $coherent = $group->isAboutAParty()
                    ? $this->provesSameParty($winner, $donor)
                    : $this->describesSameParty($winner, $donor);
                if (!$coherent) {
                    continue;
                }
                foreach ($fields as $field) {
                    if (array_key_exists($field, $values)) {
                        continue;
                    }
                    $value = $donor->trustedValue($field, self::MIN_CONFIDENCE);
                    if ($value !== null) {
                        $values[$field] = $value;
                        $provenance[$field] = $donor->documentId;
                        if ($group === FieldGroup::PARTY_IDENTITY) {
                            // Even a permitted completion is worth saying out
                            // loud: the party in the filing is now described by
                            // two documents, and only the lawyer can confirm
                            // that they are one company.
                            $borrowedIdentity[$field] = $donor->documentId;
                        }
                    }
                }
            }

            foreach ($groupConflicts as $conflict) {
                $conflicts[] = $conflict;
            }

            // An identifier read from a document that cannot be tied to this
            // party is not used, and saying nothing about it would hide the
            // very reading that made the guard refuse. The lawyer is the one
            // who can tell a second entity from a second spelling.
            if ($group === FieldGroup::PARTY_IDENTITY) {
                foreach ($donors as $other) {
                    if ($this->provesSameParty($winner, $other)) {
                        continue;
                    }
                    foreach (['cui', 'personalId', 'name'] as $field) {
                        $value = $other->trustedValue($field, self::MIN_CONFIDENCE);
                        if ($value === null || array_key_exists($field, $values)) {
                            continue;
                        }
                        $conflicts[] = new PrefillConflict(
                            scope: $scope,
                            severity: ConflictSeverity::WARNING,
                            messageKey: 'wizard.conflict.party.identity_unmatched',
                            field: $field,
                            entityKey: $entityKey,
                            options: [new ConflictOption(
                                value: $value,
                                documentId: $other->documentId,
                                documentType: $other->documentType,
                                confidence: $other->confidenceOf($field),
                            )],
                        );
                    }
                }
            }

            $this->applyPins($groupPins, $winner, $values, $provenance, $manual);
        }

        foreach ($borrowedIdentity as $field => $documentId) {
            $conflicts[] = new PrefillConflict(
                scope: $scope,
                severity: ConflictSeverity::WARNING,
                messageKey: 'wizard.conflict.party.identity_completed',
                field: $field,
                entityKey: $entityKey,
                options: [new ConflictOption(value: $values[$field], documentId: $documentId)],
            );
        }

        return new AggregatedFields(
            values: $values,
            // A value the lawyer typed is not auto-filled, whatever the form
            // does with it afterwards: the badge says a document stated it.
            autoFilled: array_values(array_diff(array_keys($values), $manual)),
            provenance: $provenance,
            conflicts: $conflicts,
        );
    }

    /**
     * The document a pin in this group points at, whatever the ranking made of
     * it.
     *
     * Searched over every source rather than the ones the identity guard let
     * through: the guard exists because nobody had decided, and here somebody
     * has. The group is still taken whole from that one document, so the choice
     * cannot produce a party built out of two files.
     *
     * @param list<FieldSource> $sources
     * @param array<string, ConflictResolution> $groupPins
     */
    private function pinnedWinner(array $sources, array $groupPins): ?FieldSource
    {
        foreach ($groupPins as $pin) {
            if ($pin->documentId === null) {
                continue;
            }
            foreach ($sources as $source) {
                if ($source->documentId === $pin->documentId) {
                    return $source;
                }
            }
        }

        return null;
    }

    /**
     * Writes the chosen values over whatever the aggregation settled on.
     *
     * Last word by design. Everything else in this class is a ranking; this is a
     * decision, and a decision that a ranking could overturn is not one.
     *
     * A choice of document only speaks for the group it won. The whole group
     * already comes from that file, so writing a second file's value onto one
     * field of it would produce a party named by one document and identified by
     * another, which is the chimera this class exists to prevent. Such a pin is
     * left unapplied: {@see ConflictResolutionService::collect()} brings the
     * choices of one group back onto one document before they get this far, and
     * this is what makes a bag that escaped it harmless.
     *
     * A typed value has no document behind it and no group to move, so it is
     * always written, on its own field, and marked as the lawyer's own.
     *
     * @param array<string, ConflictResolution> $groupPins
     * @param ?FieldSource $winner the document this group was taken from
     * @param array<string, mixed> $values
     * @param array<string, int> $provenance
     * @param list<string> $manual
     */
    private function applyPins(array $groupPins, ?FieldSource $winner, array &$values, array &$provenance, array &$manual): void
    {
        foreach ($groupPins as $field => $pin) {
            if ($pin->isManual()) {
                $values[$field] = $pin->value;
                unset($provenance[$field]);
                $manual[] = $field;

                continue;
            }
            if ($pin->documentId === null || $winner === null || $pin->documentId !== $winner->documentId) {
                continue;
            }
            $values[$field] = $pin->value;
            $provenance[$field] = $pin->documentId;
        }
    }

    /**
     * Whether two documents can be *shown* to be about the same party.
     *
     * Positive evidence is required, and silence is not evidence. A document
     * that carries a registration number and no name cannot lend that number to
     * a party another document named: the number is precisely what individuates
     * a legal person (CPC art. 194 lit. a), and a real name paired with another
     * entity's number is a party that does not exist. The absence of a
     * contradiction is not enough to make the pairing, so this is what governs
     * every value one document lends to a party another document established.
     */
    public function provesSameParty(FieldSource $a, FieldSource $b): bool
    {
        return $this->compareIdentity($a, $b, self::FILL_NAME_SIMILARITY) === true;
    }

    /**
     * Whether two documents are at least not contradicting each other about who
     * the party is. Used where the fields at stake do not identify anybody, so
     * a value borrowed from a document that names nobody cannot produce a party
     * that does not exist.
     */
    public function describesSameParty(FieldSource $a, FieldSource $b): bool
    {
        return $this->compareIdentity($a, $b, self::FILL_NAME_SIMILARITY) !== false;
    }

    /**
     * Whether two candidates count as one party rather than two.
     *
     * Stricter than {@see self::provesSameParty()} on names, and deliberately
     * decided by absence of contradiction rather than by proof: a candidate the
     * documents say nothing identifying about is not evidence of a second
     * party, and turning it into a debtor card would ask the lawyer to remove a
     * party nobody named. What it cannot do is pull values across, which
     * {@see self::provesSameParty()} still governs inside the cluster.
     */
    public function isSameIdentity(FieldSource $a, FieldSource $b): bool
    {
        return $this->compareIdentity($a, $b, self::IDENTITY_NAME_SIMILARITY) !== false;
    }

    /**
     * True when the two sources are provably the same party, false when they
     * are provably different, null when the documents do not say.
     *
     * All of it reads legible values rather than trustworthy ones: a reading
     * too weak to be written into the form is still a reading, and treating a
     * dissimilar name as silence is what lets a stranger's document pass for
     * the party's own.
     */
    private function compareIdentity(FieldSource $a, FieldSource $b, float $nameThreshold): ?bool
    {
        $cuiA = $this->normalizedCui($a->trustedValue('cui', self::LEGIBLE_CONFIDENCE));
        $cuiB = $this->normalizedCui($b->trustedValue('cui', self::LEGIBLE_CONFIDENCE));
        if ($cuiA !== null && $cuiB !== null) {
            return $cuiA === $cuiB;
        }

        $cnpA = $this->digitsOnly($a->trustedValue('personalId', self::LEGIBLE_CONFIDENCE));
        $cnpB = $this->digitsOnly($b->trustedValue('personalId', self::LEGIBLE_CONFIDENCE));
        if ($cnpA !== null && $cnpB !== null) {
            return $cnpA === $cnpB;
        }

        // A company registration number on one side and a personal number on
        // the other are two kinds of person, whatever the names look like.
        if (($cuiA !== null && $cnpB !== null) || ($cnpA !== null && $cuiB !== null)) {
            return false;
        }

        $typeA = $a->trustedValue('personType', self::LEGIBLE_CONFIDENCE);
        $typeB = $b->trustedValue('personType', self::LEGIBLE_CONFIDENCE);
        if (is_string($typeA) && is_string($typeB) && $typeA !== $typeB) {
            return false;
        }

        $nameA = $a->trustedValue('name', self::LEGIBLE_CONFIDENCE);
        $nameB = $b->trustedValue('name', self::LEGIBLE_CONFIDENCE);
        if (is_string($nameA) && is_string($nameB)) {
            // The legal form is part of who the party is. Two members of one
            // group differ by nothing else, and the rest of the comparison
            // strips exactly that, so it is decided before the similarity.
            $formA = $this->statedLegalForm($nameA);
            $formB = $this->statedLegalForm($nameB);
            if ($formA !== null && $formB !== null && $formA !== $formB) {
                return false;
            }

            return $this->nameSimilarity($nameA, $nameB) >= $nameThreshold;
        }

        return null;
    }

    /**
     * Similarity of two party names, 0..1, ignoring legal form and punctuation.
     *
     * Symmetric by construction: `similar_text` is not, so the pair is ordered
     * before it is compared. Determinism here is what keeps two runs over the
     * same documents from producing a different number of debtors.
     */
    public function nameSimilarity(string $a, string $b): float
    {
        $left = $this->normalizedName($a);
        $right = $this->normalizedName($b);
        if ($left === '' || $right === '') {
            return 0.0;
        }
        if ($left === $right) {
            return 1.0;
        }
        if (strcmp($left, $right) > 0) {
            [$left, $right] = [$right, $left];
        }

        similar_text($left, $right, $percent);

        return $percent / 100;
    }

    /**
     * Sources that say something usable about the group, best first.
     *
     * @param list<FieldSource> $sources
     * @param list<string> $fields
     * @return list<FieldSource>
     */
    private function rank(array $sources, FieldGroup $group, array $fields): array
    {
        $scored = [];
        foreach ($sources as $source) {
            $present = [];
            foreach ($fields as $field) {
                if ($source->trustedValue($field, self::MIN_CONFIDENCE) !== null) {
                    $present[] = $source->confidenceOf($field);
                }
            }
            if ($present === []) {
                continue;
            }
            $coverage = count($present);
            $meanConfidence = array_sum($present) / $coverage;
            $scored[] = [
                'source' => $source,
                'score' => $this->authority->weight($source->documentType, $group) * self::AUTHORITY_SCALE
                    + $meanConfidence * self::CONFIDENCE_SCALE
                    + $coverage,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            // Ties on the score fall through to the document id, which is
            // unique, so the order is total and repeatable.
            return $b['score'] <=> $a['score']
                ?: $a['source']->documentId <=> $b['source']->documentId;
        });

        return array_map(static fn (array $row): FieldSource => $row['source'], $scored);
    }

    /**
     * The disagreements worth putting in front of the lawyer.
     *
     * Only identity fields produce a blocking conflict: a second opinion on an
     * address is a warning, a second opinion on who the party is stops the
     * filing. Fields nobody disputes produce nothing.
     *
     * @param list<FieldSource> $ranked
     * @param list<string> $fields
     * @return list<PrefillConflict>
     */
    private function collectConflicts(array $ranked, array $fields, ConflictScope $scope, ?string $entityKey): array
    {
        $conflicts = [];
        foreach ($fields as $field) {
            if (in_array($field, self::FREE_TEXT_FIELDS, true)) {
                continue;
            }
            $options = [];
            $seen = [];
            foreach ($ranked as $source) {
                $value = $source->trustedValue($field, self::MIN_CONFIDENCE);
                if ($value === null) {
                    continue;
                }
                $fingerprint = $this->comparable($field, $value);
                if (isset($seen[$fingerprint])) {
                    continue;
                }
                $seen[$fingerprint] = true;
                $options[] = new ConflictOption(
                    value: $value,
                    documentId: $source->documentId,
                    documentType: $source->documentType,
                    confidence: $source->confidenceOf($field),
                );
            }
            if (count($options) < 2) {
                continue;
            }

            $conflicts[] = new PrefillConflict(
                scope: $scope,
                severity: $this->severityFor($field),
                messageKey: 'wizard.conflict.field.' . $field,
                field: $field,
                entityKey: $entityKey,
                options: $options,
                // The first option is the one the aggregation took: the ranking
                // is already sorted, and the winner's value was written first.
                suggestedIndex: 0,
            );
        }

        return $conflicts;
    }

    private function severityFor(string $field): ConflictSeverity
    {
        return match ($field) {
            'cui', 'personalId', 'amount', 'dueDate' => ConflictSeverity::ERROR,
            'name', 'address', 'county', 'locality', 'penaltyType', 'contractualPenaltyRate' => ConflictSeverity::WARNING,
            default => ConflictSeverity::INFO,
        };
    }

    /**
     * A comparable form of a value, so two spellings of one thing do not read
     * as a conflict the lawyer has to resolve.
     */
    private function comparable(string $field, mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value)) {
            return match ($field) {
                'cui' => (string) $this->normalizedCui($value),
                'personalId', 'phone' => (string) $this->digitsOnly($value),
                'name' => $this->normalizedName($value),
                default => mb_strtoupper(trim($value)),
            };
        }
        if (is_float($value) || is_int($value)) {
            return sprintf('%.4f', (float) $value);
        }

        return is_scalar($value) ? (string) $value : serialize($value);
    }

    /**
     * The digits of a Romanian registration number. The `RO` prefix states VAT
     * registration, not identity, and leading zeros are typography.
     */
    private function normalizedCui(mixed $raw): ?string
    {
        $digits = $this->digitsOnly($raw);
        if ($digits === null) {
            return null;
        }
        $trimmed = ltrim($digits, '0');

        return $trimmed === '' ? $digits : $trimmed;
    }

    private function digitsOnly(mixed $raw): ?string
    {
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * A party name reduced to what identifies it: legal form, punctuation and
     * spacing dropped, because "S.C. ALFA S.R.L." and "Alfa SRL" are one
     * company written twice.
     */
    private function normalizedName(string $raw): string
    {
        $tokens = $this->nameTokens($raw);
        $kept = array_values(array_filter($tokens, static fn (string $t): bool => !in_array($t, self::CORE_LEGAL_FORMS, true)
            && !in_array($t, self::GENERIC_LEGAL_FORMS, true)));

        // A name made only of a legal form keeps it: dropping everything would
        // make it identical to every other such name.
        return implode(' ', $kept === [] ? $tokens : $kept);
    }

    /**
     * The legal form the name states, or null when it states none.
     */
    private function statedLegalForm(string $raw): ?string
    {
        foreach ($this->nameTokens($raw) as $token) {
            if (in_array($token, self::CORE_LEGAL_FORMS, true)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * The words of a party name, with runs of single letters glued back
     * together: a dotted legal form arrives here as "S R L", and reading it as
     * three words is what would make "S.C. ALFA S.R.L." and "ALFA SRL" look
     * like two companies.
     *
     * @return list<string>
     */
    private function nameTokens(string $raw): array
    {
        $value = mb_strtoupper(trim($raw));
        $value = str_replace(['Ș', 'Ş', 'Ț', 'Ţ', 'Ă', 'Â', 'Î'], ['S', 'S', 'T', 'T', 'A', 'A', 'I'], $value);
        $value = preg_replace('/[^A-Z0-9]+/u', ' ', $value) ?? $value;

        $tokens = [];
        $initials = '';
        foreach (preg_split('/\s+/u', trim($value)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            if (mb_strlen($word) === 1) {
                $initials .= $word;

                continue;
            }
            if ($initials !== '') {
                $tokens[] = $initials;
                $initials = '';
            }
            $tokens[] = $word;
        }
        if ($initials !== '') {
            $tokens[] = $initials;
        }

        return $tokens;
    }

    /**
     * @param array<string, FieldGroup> $fieldGroups
     * @return array<string, list<string>> group value to its fields, in the
     *         order the caller declared them
     */
    private function fieldsByGroup(array $fieldGroups): array
    {
        $byGroup = [];
        foreach ($fieldGroups as $field => $group) {
            $byGroup[$group->value][] = $field;
        }

        // Identity first, because it anchors every other group of the same
        // party. The remaining order is the caller's, which is stable.
        if (isset($byGroup[FieldGroup::PARTY_IDENTITY->value])) {
            $identity = $byGroup[FieldGroup::PARTY_IDENTITY->value];
            unset($byGroup[FieldGroup::PARTY_IDENTITY->value]);
            $byGroup = [FieldGroup::PARTY_IDENTITY->value => $identity] + $byGroup;
        }

        return $byGroup;
    }
}
