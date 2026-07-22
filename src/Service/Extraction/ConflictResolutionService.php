<?php

declare(strict_types=1);

namespace App\Service\Extraction;

use App\DTO\Extraction\ConflictOption;
use App\DTO\Extraction\ConflictResolution;
use App\DTO\Extraction\PrefillConflict;
use App\Enum\ConflictScope;
use App\Enum\FieldGroup;
use App\Util\PiiMasker;

/**
 * Turns what the lawyer picked in the conflicts panel into decisions the rest of
 * the wizard can apply, and keeps those decisions provable.
 *
 * Four things are deliberate here.
 *
 * A choice is stored as a pin on a document, not as a loose value. Choosing the
 * registration number stated by the invoice means the party in the filing is the
 * one the invoice describes, so the whole group of fields follows that document
 * (see {@see CoherentAggregator}). Copying only the number across would rebuild
 * exactly the chimera the aggregation exists to prevent: a name from one file and
 * an identifier from another, naming a party that exists nowhere.
 *
 * Two choices in the same group are brought back onto one document. Picking the
 * name from one file and the number from another is the same chimera arrived at
 * by hand, so the later decision carries the group and the earlier ones are moved
 * onto the same file rather than left contradicting it.
 *
 * A typed value is the one case where no document backs the field. It is applied
 * to that field alone and marked manual, because there is no group to follow and
 * inventing one would be worse: the lawyer stated the value, so the coherence of
 * it is theirs, and the audit entry says so. It is also the only value nothing
 * downstream can catch, so it is checked here as strictly as the form checks the
 * same field: a registration number that fails its control digit belongs to
 * nobody, and a sum that is not positive is not something a court can order paid.
 *
 * A stored choice is verified against the current options before it is used. The
 * documents can change between two visits to a step, and a pick that silently
 * slid onto another value would be a decision nobody made.
 */
final class ConflictResolutionService
{
    /** Marker posted by the "I enter the value myself" radio. */
    public const MANUAL_CHOICE = 'manual';

    /** Fields whose typed value is a sum rather than text. */
    private const NUMERIC_FIELDS = ['amount', 'contractualPenaltyRate', 'paidAmount'];

    /** Fields whose typed value is a date rather than text. */
    private const DATE_FIELDS = ['dueDate', 'invoiceDate', 'contractDate', 'documentDate'];

    /** What DECIMAL(12,2) can carry, past which the sum fails at flush. */
    private const MAX_SUM = 9_999_999_999.99;

    /** A penalty is a rate per day; past this it is a typing accident. */
    private const MAX_RATE = 100.0;

    /** Text fields whose length the entities and the forms cap. */
    private const MAX_TEXT_LENGTH = [
        'name' => 255,
        'county' => 100,
        'locality' => 150,
        'address' => 500,
        'description' => 1000,
    ];

    /**
     * The resolutions after a step submission: what was already decided, updated
     * with what this request decided.
     *
     * Only conflicts shown on this step can be changed by it. A resolution taken
     * on another step stays untouched, which is what lets the lawyer walk back
     * and forth without losing decisions.
     *
     * @param array<string, mixed> $choices posted radio values, conflict key to index or "manual"
     * @param array<string, mixed> $typed posted free-text values, conflict key to raw string
     * @param list<PrefillConflict> $conflicts the conflicts this step showed
     * @param array<string, ConflictResolution> $existing
     * @return array<string, ConflictResolution>
     */
    public function collect(array $choices, array $typed, array $conflicts, array $existing): array
    {
        $resolved = $existing;
        foreach ($conflicts as $conflict) {
            $key = $conflict->key();
            if (!array_key_exists($key, $choices)) {
                continue;
            }

            $resolution = $this->fromChoice($conflict, $choices[$key], $typed[$key] ?? null);
            if ($resolution === null) {
                // An unusable answer (no value typed, an index that is not on
                // the list) leaves the conflict open rather than half decided.
                unset($resolved[$key]);

                continue;
            }
            $resolved[$key] = $resolution;
        }

        return $this->harmonize($resolved, $conflicts, array_keys($choices));
    }

    /**
     * One document per group of fields, even when the lawyer picked twice.
     *
     * A group of fields is taken whole from one file precisely because its
     * fields only mean anything together. Two choices naming two files inside
     * one group would put a name from one document and a registration number
     * from another into the filing, which is the chimera the aggregation exists
     * to prevent, reached by hand instead of by ranking.
     *
     * The decision that carries the group is the one just made, and among
     * several the one on the conflict that was blocking: that is the field the
     * lawyer was stopped on, so it is the one they answered. The other choices
     * are moved onto the same file rather than dropped, so the panel goes on
     * showing a decision for every field it asked about.
     *
     * @param array<string, ConflictResolution> $resolved
     * @param list<PrefillConflict> $conflicts
     * @param list<string> $decidedNow keys the current request answered
     * @return array<string, ConflictResolution>
     */
    private function harmonize(array $resolved, array $conflicts, array $decidedNow): array
    {
        $byKey = [];
        $position = [];
        foreach ($conflicts as $index => $conflict) {
            $byKey[$conflict->key()] = $conflict;
            $position[$conflict->key()] = $index;
        }

        /** @var array<string, list<string>> $groups */
        $groups = [];
        foreach ($resolved as $key => $resolution) {
            if ($resolution->documentId === null || !isset($byKey[$key])) {
                continue;
            }
            $groups[$this->groupKey($resolution->scope, $resolution->entityKey, $resolution->field)][] = $key;
        }

        foreach ($groups as $keys) {
            if (count($keys) < 2) {
                continue;
            }
            usort($keys, function (string $a, string $b) use ($resolved, $byKey, $position, $decidedNow): int {
                return $this->decisionWeight($b, $byKey, $position, $decidedNow)
                    <=> $this->decisionWeight($a, $byKey, $position, $decidedNow);
            });
            $winner = $resolved[$keys[0]]->documentId;
            foreach (array_slice($keys, 1) as $key) {
                if ($resolved[$key]->documentId === $winner) {
                    continue;
                }
                $aligned = $this->onDocument($byKey[$key], $winner);
                if ($aligned === null) {
                    unset($resolved[$key]);

                    continue;
                }
                $resolved[$key] = $aligned;
            }
        }

        return $resolved;
    }

    /**
     * How strongly one decision speaks for its group: answered in this request
     * first, then answered on a conflict that was blocking, then the order the
     * panel put the conflicts in.
     *
     * @param array<string, PrefillConflict> $byKey
     * @param array<string, int> $position
     * @param list<string> $decidedNow
     * @return list<int>
     */
    private function decisionWeight(string $key, array $byKey, array $position, array $decidedNow): array
    {
        return [
            in_array($key, $decidedNow, true) ? 1 : 0,
            ($byKey[$key] ?? null)?->blocks() === true ? 1 : 0,
            $position[$key] ?? 0,
        ];
    }

    /**
     * The same conflict answered with the value the winning document states,
     * or null when that document says nothing about this field.
     */
    private function onDocument(PrefillConflict $conflict, int $documentId): ?ConflictResolution
    {
        foreach ($conflict->options as $index => $option) {
            if ($option->documentId !== $documentId) {
                continue;
            }

            return new ConflictResolution(
                conflictKey: $conflict->key(),
                scope: $conflict->scope,
                field: $conflict->field,
                entityKey: $conflict->entityKey,
                value: $option->value,
                optionIndex: $index,
                documentId: $option->documentId,
                documentType: $option->documentType,
                optionSignature: $option->displayValue(),
            );
        }

        return null;
    }

    /**
     * The unit a choice moves: the subject it is about plus the group of fields
     * that only mean anything together.
     *
     * Positions of the claim have no field groups of their own: one row is one
     * reading of one invoice, so its sum and its due date come from the same
     * file or the position describes an invoice nobody issued.
     */
    private function groupKey(ConflictScope $scope, ?string $entityKey, ?string $field): string
    {
        $subject = $scope->value . '|' . ($entityKey ?? '-');
        if ($scope === ConflictScope::CLAIM_ITEM) {
            return $subject . '|ROW';
        }
        if ($field === null) {
            return $subject . '|-';
        }

        $map = match ($scope) {
            ConflictScope::CREDITOR, ConflictScope::DEBTOR => FieldGroup::partyFieldMap(),
            ConflictScope::CLAIM => FieldGroup::claimFieldMap(),
            default => [],
        };

        return $subject . '|' . ($map[$field]?->value ?? 'FIELD:' . $field);
    }

    /**
     * The resolutions that still describe the conflicts as they are now.
     *
     * @param array<string, ConflictResolution> $resolutions
     * @param list<PrefillConflict> $conflicts
     * @param bool $dropUnmatched whether a decision with no conflict left to
     *        match is thrown away; true only where every conflict of the file is
     *        on screen, so absence means the disagreement is gone
     * @return array<string, ConflictResolution>
     */
    public function reconcile(array $resolutions, array $conflicts, bool $dropUnmatched = false): array
    {
        $byKey = [];
        foreach ($conflicts as $conflict) {
            $byKey[$conflict->key()] = $conflict;
        }

        $kept = [];
        foreach ($resolutions as $key => $resolution) {
            $conflict = $byKey[$key] ?? null;
            if ($conflict === null) {
                if ($dropUnmatched) {
                    // The documents changed and this disagreement no longer
                    // exists. Keeping the decision would let it be reapplied
                    // later against a conflict key that means something else.
                    continue;
                }
                // Belongs to a step that is not on screen; nothing here can say
                // whether it is still valid, so it is left alone.
                $kept[$key] = $resolution;

                continue;
            }
            if ($resolution->isManual()) {
                $kept[$key] = $resolution;

                continue;
            }
            $option = $conflict->options[$resolution->optionIndex] ?? null;
            if ($option instanceof ConflictOption && $option->displayValue() === $resolution->optionSignature) {
                $kept[$key] = $resolution;
            }
        }

        return $kept;
    }

    /**
     * Blocking conflicts the lawyer can settle by choosing, and has not.
     *
     * @param list<PrefillConflict> $conflicts
     * @param array<string, ConflictResolution> $resolutions
     * @return list<PrefillConflict>
     */
    public function pendingChoices(array $conflicts, array $resolutions): array
    {
        return array_values(array_filter(
            $conflicts,
            static fn (PrefillConflict $c): bool => $c->blocks()
                && $c->options !== []
                && !isset($resolutions[$c->key()]),
        ));
    }

    /**
     * Blocking conflicts that offer nothing to choose between, so the only thing
     * the lawyer can do is state they have read them. Two invoices issued to two
     * different debtors is the case: the answer is not a value, it is whether
     * this is one file or two.
     *
     * @param list<PrefillConflict> $conflicts
     * @return list<PrefillConflict>
     */
    public function pendingAcknowledgement(array $conflicts): array
    {
        return array_values(array_filter(
            $conflicts,
            static fn (PrefillConflict $c): bool => $c->blocks() && $c->options === [],
        ));
    }

    /**
     * The conflicts this request answered and could not keep the answer to.
     *
     * A refused value has to be said out loud on the field it was typed into. It
     * disappearing from the panel with the disagreement still open reads as the
     * decision having been lost rather than refused.
     *
     * @param array<string, mixed> $choices posted radio values
     * @param list<PrefillConflict> $conflicts
     * @param array<string, ConflictResolution> $resolutions
     * @return list<string>
     */
    public function rejectedChoices(array $choices, array $conflicts, array $resolutions): array
    {
        $rejected = [];
        foreach ($conflicts as $conflict) {
            $key = $conflict->key();
            if (array_key_exists($key, $choices) && !isset($resolutions[$key])) {
                $rejected[] = $key;
            }
        }

        return $rejected;
    }

    /**
     * The fingerprint of a set of conflicts nothing can be chosen between.
     *
     * What the lawyer states is that they have read *these* disagreements. A new
     * document can raise another one, and a flag that stayed true would carry
     * their statement onto something they never saw, so the statement is bound
     * to the set it was made about.
     *
     * @param list<PrefillConflict> $conflicts
     */
    public function acknowledgementFingerprint(array $conflicts): string
    {
        $keys = array_map(static fn (PrefillConflict $c): string => $c->key(), $conflicts);
        sort($keys);

        return hash('sha256', implode('|', $keys));
    }

    /**
     * The resolutions that bear on one subject of one scope, by field, ready for
     * the aggregation to pin.
     *
     * @param array<string, ConflictResolution> $resolutions
     * @return array<string, ConflictResolution>
     */
    public function pinsFor(array $resolutions, ConflictScope $scope, ?string $entityKey = null): array
    {
        $pins = [];
        foreach ($resolutions as $resolution) {
            if ($resolution->scope !== $scope || $resolution->field === null) {
                continue;
            }
            if ($resolution->entityKey !== $entityKey) {
                continue;
            }
            $pins[$resolution->field] = $resolution;
        }

        return $pins;
    }

    /**
     * The resolutions of one scope, whatever subject they are about.
     *
     * @param array<string, ConflictResolution> $resolutions
     * @return list<ConflictResolution>
     */
    public function ofScope(array $resolutions, ConflictScope $scope): array
    {
        return array_values(array_filter(
            $resolutions,
            static fn (ConflictResolution $r): bool => $r->scope === $scope,
        ));
    }

    /**
     * What goes into the audit log: every conflict that was put to the lawyer,
     * the values they were offered, the one they retained and where it came
     * from. The point is evidential. Months later the file has to answer why one
     * sum was claimed and not the other one the documents also stated.
     *
     * @param list<PrefillConflict> $conflicts
     * @param array<string, ConflictResolution> $resolutions
     * @param array<int, array{filename: ?string, contentHash: ?string}> $documents
     *        how each source file can be named years later: an id alone does not
     *        say which document in the filing carried the value, and the row it
     *        points at may be gone
     * @param array<string, array{at: string, fingerprint: string}> $acknowledgements
     *        by conflict key, for the blocking disagreements that offer nothing
     *        to choose between: all the lawyer can do there is state they read
     *        them, and that statement is part of the record
     * @return list<array<string, mixed>>
     */
    public function auditEntries(array $conflicts, array $resolutions, array $documents = [], array $acknowledgements = []): array
    {
        $entries = [];
        foreach ($conflicts as $conflict) {
            $key = $conflict->key();
            $resolution = $resolutions[$key] ?? null;
            $entries[] = [
                'key' => $key,
                'scope' => $conflict->scope->value,
                'severity' => $conflict->severity->value,
                'field' => $conflict->field,
                'entityKey' => $conflict->entityKey,
                'messageKey' => $conflict->messageKey,
                'options' => array_map(
                    fn (ConflictOption $o): array => $o->toArray() + $this->documentDescriptor($o->documentId, $documents),
                    $conflict->options,
                ),
                'suggestedIndex' => $conflict->suggestedIndex,
                'resolution' => $resolution === null
                    ? null
                    : $resolution->toArray() + $this->documentDescriptor($resolution->documentId, $documents),
                'acknowledgement' => $acknowledgements[$key] ?? null,
            ];
        }

        return $entries;
    }

    /**
     * @param array<int, array{filename: ?string, contentHash: ?string}> $documents
     * @return array{documentFilename: ?string, documentContentHash: ?string}
     */
    private function documentDescriptor(?int $documentId, array $documents): array
    {
        $descriptor = $documentId === null ? null : ($documents[$documentId] ?? null);

        return [
            'documentFilename' => $descriptor['filename'] ?? null,
            'documentContentHash' => $descriptor['contentHash'] ?? null,
        ];
    }

    /**
     * @param mixed $choice posted radio value
     * @param mixed $typed posted free-text value for the same conflict
     */
    private function fromChoice(PrefillConflict $conflict, mixed $choice, mixed $typed): ?ConflictResolution
    {
        if (!is_string($choice) && !is_int($choice)) {
            return null;
        }

        if ((string) $choice === self::MANUAL_CHOICE) {
            $value = $this->coerce($conflict->field, is_string($typed) ? $typed : '');

            return $value === null ? null : new ConflictResolution(
                conflictKey: $conflict->key(),
                scope: $conflict->scope,
                field: $conflict->field,
                entityKey: $conflict->entityKey,
                value: $value,
            );
        }

        if (!is_numeric($choice)) {
            return null;
        }
        $index = (int) $choice;
        $option = $conflict->options[$index] ?? null;
        if (!$option instanceof ConflictOption) {
            return null;
        }

        return new ConflictResolution(
            conflictKey: $conflict->key(),
            scope: $conflict->scope,
            field: $conflict->field,
            entityKey: $conflict->entityKey,
            value: $option->value,
            optionIndex: $index,
            documentId: $option->documentId,
            documentType: $option->documentType,
            optionSignature: $option->displayValue(),
        );
    }

    /**
     * A typed value in the shape the field carries elsewhere, or null when it
     * cannot be the value of that field.
     *
     * A sum stays a float and a due date stays a date, because both are read by
     * calculators further down and a string would either break them or be
     * silently ignored. Anything that does not survive the checks the same field
     * gets in the form is refused rather than accepted loosely: a refusal is
     * visible on screen, a misread sum is not.
     */
    private function coerce(?string $field, string $raw): mixed
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        if ($field !== null && in_array($field, self::NUMERIC_FIELDS, true)) {
            return $this->toSum($field, $trimmed);
        }

        if ($field !== null && in_array($field, self::DATE_FIELDS, true)) {
            foreach (['d.m.Y', 'd/m/Y', 'Y-m-d'] as $format) {
                $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $trimmed);
                if ($parsed instanceof \DateTimeImmutable) {
                    return $parsed;
                }
            }

            return null;
        }

        return $this->toText($field, $trimmed);
    }

    /**
     * A figure written the way it is written in Romania.
     *
     * "1.500" is one thousand five hundred here, and read as a decimal point it
     * becomes one leu and a half: the petition would claim a thousandth of the
     * debt with nothing on screen saying so. Groups of exactly three digits are
     * what tells a thousands separator from a decimal one.
     */
    private function toSum(string $field, string $raw): ?float
    {
        $normalized = str_replace([' ', "\u{a0}"], '', $raw);
        if (str_contains($normalized, ',')) {
            $normalized = str_replace(['.', ','], ['', '.'], $normalized);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $normalized) === 1) {
            $normalized = str_replace('.', '', $normalized);
        }

        // Plain decimal notation only: exponentials read as numbers and land on
        // a figure nobody typed.
        if (preg_match('/^-?\d+(\.\d+)?$/', $normalized) !== 1) {
            return null;
        }

        $value = (float) $normalized;
        $ceiling = $field === 'contractualPenaltyRate' ? self::MAX_RATE : self::MAX_SUM;

        // Zero is arithmetically valid and legally meaningless, and a negative
        // sum would be subtracted from what is claimed rather than added.
        return $value > 0.0 && $value <= $ceiling ? $value : null;
    }

    /**
     * Text that can stand in the field it was typed for.
     *
     * The identifiers are the point: a registration number that fails its
     * control digit belongs to no company (CPC art. 194 lit. a asks the filing
     * to identify the party), and the form would have refused the very same
     * string had it been typed one panel over.
     */
    private function toText(?string $field, string $value): ?string
    {
        $valid = match ($field) {
            'cui' => PiiMasker::isValidCui(str_starts_with(strtoupper($value), 'RO') ? substr($value, 2) : $value),
            'personalId' => PiiMasker::isValidCnp($value),
            'iban' => preg_match('/^RO\d{2}[A-Z]{4}\d{16}$/', strtoupper(str_replace(' ', '', $value))) === 1,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            default => mb_strlen($value) <= (self::MAX_TEXT_LENGTH[$field] ?? 255),
        };

        return $valid ? $value : null;
    }
}
