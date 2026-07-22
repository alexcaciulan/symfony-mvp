<?php

declare(strict_types=1);

namespace App\Service\Extraction;

use App\DTO\Extraction\ConflictResolution;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Enum\ConflictScope;

/**
 * Moves what the lawyer chose in the conflicts panel into the data of the step.
 *
 * The panel sits beside the form, not inside it, so the fields on screen were
 * rendered before the choice was made and a submission carries the value the
 * ranking had preferred. Recording the decision and filing the other value is
 * worse than not offering the choice at all: the audit would then state a number
 * the filing contradicts, and an act that contradicts the file is evidence
 * against the lawyer rather than for them.
 *
 * Two rules, and the second is the delicate one.
 *
 * The field that was chosen always takes the chosen value. That is the decision,
 * and a decision a form default can overturn is not one.
 *
 * The other fields of the party follow the chosen document only while they still
 * carry what the machine put there. Choosing the registration number stated by
 * the invoice means the party is the one the invoice describes, so its name
 * comes along, otherwise the file names a company by one document and identifies
 * it by another. But a field the lawyer typed themselves is their own statement
 * about the party, and no aggregation gets to overwrite it: comparing what was
 * submitted against what the aggregation had produced is what separates the two.
 */
final class ConflictChoiceApplier
{
    /**
     * Extraction speaks of `county`/`locality`; both wizard forms expose them as
     * `addressCounty`/`addressLocality`.
     */
    private const PROPERTY_BY_FIELD = [
        'county' => 'addressCounty',
        'locality' => 'addressLocality',
    ];

    public function __construct(
        private readonly ConflictResolutionService $resolutionService = new ConflictResolutionService(),
    ) {}

    /**
     * @param ?Step1CreditorData $before what the aggregation produced before the
     *        choice, so a field the lawyer edited can be told from one they left
     *        alone; null where only the chosen fields are to be enforced
     * @param array<string, ConflictResolution> $resolutions
     * @return list<array{target: object, property: string, previous: mixed, conflictKey: ?string}>
     *         what was written, so the caller can check each value against the
     *         constraints of the field it landed on
     */
    public function applyToCreditor(
        Step1CreditorData $posted,
        ?Step1CreditorData $before,
        Step1CreditorData $after,
        array $resolutions,
    ): array {
        return $this->move($posted, $before, $after, $this->pinnedProperties($resolutions, ConflictScope::CREDITOR));
    }

    /**
     * Debtors are matched by their position, which is the cluster the conflict
     * was raised against: a choice landing on another party would be a decision
     * nobody made, so an entry the lawyer added or removed is left alone.
     *
     * @param array<string, ConflictResolution> $resolutions
     * @return list<array{target: object, property: string, previous: mixed, conflictKey: ?string}>
     */
    public function applyToDebtors(
        Step2DebtorsData $posted,
        ?Step2DebtorsData $before,
        Step2DebtorsData $after,
        array $resolutions,
    ): array {
        $moved = [];
        foreach ($posted->debtors as $index => $entry) {
            $afterEntry = $after->debtors[$index] ?? null;
            if ($afterEntry === null) {
                continue;
            }
            foreach ($this->move(
                $entry,
                $before?->debtors[$index] ?? null,
                $afterEntry,
                $this->pinnedProperties($resolutions, ConflictScope::DEBTOR, 'debtor-' . $index),
            ) as $change) {
                $moved[] = $change;
            }
        }

        return $moved;
    }

    /**
     * @param array<string, ConflictResolution> $resolutions
     * @return list<array{target: object, property: string, previous: mixed, conflictKey: ?string}>
     */
    public function applyToClaim(
        Step3ClaimData $posted,
        ?Step3ClaimData $before,
        Step3ClaimData $after,
        array $resolutions,
    ): array {
        return $this->move($posted, $before, $after, $this->pinnedProperties($resolutions, ConflictScope::CLAIM));
    }

    /**
     * The form properties a decision bears on, by property name.
     *
     * @param array<string, ConflictResolution> $resolutions
     * @return array<string, ConflictResolution>
     */
    private function pinnedProperties(array $resolutions, ConflictScope $scope, ?string $entityKey = null): array
    {
        $properties = [];
        foreach ($this->resolutionService->pinsFor($resolutions, $scope, $entityKey) as $field => $pin) {
            $properties[self::PROPERTY_BY_FIELD[$field] ?? $field] = $pin;
        }

        return $properties;
    }

    /**
     * @param array<string, ConflictResolution> $pinned
     * @return list<array{target: object, property: string, previous: mixed, conflictKey: ?string}>
     */
    private function move(object $posted, ?object $before, object $after, array $pinned): array
    {
        $moved = [];
        foreach ($this->trackedProperties($after) as $property) {
            $afterValue = $after->{$property};
            if (!array_key_exists($property, $pinned)) {
                if ($before === null || $this->same($before->{$property}, $afterValue)) {
                    continue;
                }
                if (!$this->same($posted->{$property}, $before->{$property})) {
                    // Written by the lawyer, so it stays theirs.
                    continue;
                }
            }
            if ($this->same($posted->{$property}, $afterValue)) {
                continue;
            }
            $previous = $posted->{$property};
            $posted->{$property} = $afterValue;
            $this->markOrigin($posted, $after, $property);
            $moved[] = [
                'target' => $posted,
                'property' => $property,
                'previous' => $previous,
                'conflictKey' => ($pinned[$property] ?? null)?->conflictKey,
            ];
        }

        return $moved;
    }

    /**
     * The badge says a document stated the value, so a field the lawyer typed
     * into the panel loses it and a field that moved onto a chosen document
     * keeps it.
     */
    private function markOrigin(object $posted, object $after, string $property): void
    {
        if (!property_exists($posted, 'autoFilled') || !property_exists($after, 'autoFilled')) {
            return;
        }
        /** @var list<string> $current */
        $current = $posted->autoFilled;
        /** @var list<string> $source */
        $source = $after->autoFilled;

        $current = array_values(array_filter($current, static fn (string $f): bool => $f !== $property));
        if (in_array($property, $source, true)) {
            $current[] = $property;
        }
        $posted->autoFilled = $current;
    }

    /**
     * @return list<string>
     */
    private function trackedProperties(object $dto): array
    {
        $names = [];
        foreach (get_object_vars($dto) as $name => $_) {
            if ($name === 'autoFilled') {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Dates carry a time nobody typed, so they are compared by the day they
     * stand for; everything else is compared by identity, because a string and
     * a number that read alike are not the same value in a filing.
     */
    private function same(mixed $a, mixed $b): bool
    {
        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a->format('Y-m-d') === $b->format('Y-m-d');
        }

        return $a === $b;
    }
}
