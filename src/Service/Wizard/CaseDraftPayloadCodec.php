<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\DTO\Extraction\ConflictResolution;
use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\CaseDraft;

/**
 * Turns the bag into the bytes a CaseDraft row stores, and back.
 *
 * The format is PHP's own serialization of the bag's array, the same bytes
 * the session used to hold, so moving the state into a table changes nothing
 * about what the DTOs have to support. Reading back is strict: only the DTO
 * classes of the bag may be instantiated, and a payload that no longer maps
 * onto the current classes, or carries a version this code does not know,
 * is reported as unreadable rather than quietly reduced to an empty bag.
 *
 * A draft lives for weeks across deployments, so a DTO property added later
 * must have a default and must not be promoted (see WizardBag).
 */
final class CaseDraftPayloadCodec
{
    /** Bumped when the stored shape changes in a way the reader cannot absorb. */
    public const PAYLOAD_VERSION = 1;

    /**
     * Classes a payload may instantiate. Enums are not subject to this list
     * (PHP always restores them), dates are, DTOs are. Anything else becomes an
     * incomplete class and marks the payload unreadable.
     */
    public const ALLOWED_CLASSES = [
        Step1CreditorData::class,
        Step2DebtorsData::class,
        Step2DebtorEntry::class,
        Step3ClaimData::class,
        ClaimItemRow::class,
        ConflictResolution::class,
        \DateTimeImmutable::class,
        \DateTime::class,
    ];

    public function encode(WizardBag $bag): string
    {
        return serialize($bag->toArray());
    }

    /** The bytes of an empty bag, so a store can tell "nothing to keep" from a change. */
    public function emptyPayload(): string
    {
        return $this->encode(new WizardBag());
    }

    /**
     * The stored array, or null when the payload cannot be read with the
     * current classes.
     *
     * @return ?array<string, mixed>
     */
    public function decode(string $payload, int $payloadVersion): ?array
    {
        if ($payloadVersion !== self::PAYLOAD_VERSION || $payload === '') {
            return null;
        }

        set_error_handler(static function (int $severity, string $message): never {
            throw new \RuntimeException($message, $severity);
        });
        try {
            $raw = unserialize($payload, ['allowed_classes' => self::ALLOWED_CLASSES]);
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }

        if (!is_array($raw) || self::holdsIncompleteClass($raw)) {
            return null;
        }

        return $raw;
    }

    public function isReadable(CaseDraft $draft): bool
    {
        return $this->decode($draft->getPayload(), $draft->getPayloadVersion()) !== null;
    }

    private static function holdsIncompleteClass(mixed $value): bool
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::holdsIncompleteClass($item)) {
                    return true;
                }
            }

            return false;
        }
        if (is_object($value) && !$value instanceof \UnitEnum && !$value instanceof \DateTimeInterface) {
            foreach ((array) $value as $property) {
                if (self::holdsIncompleteClass($property)) {
                    return true;
                }
            }
        }

        return false;
    }
}
