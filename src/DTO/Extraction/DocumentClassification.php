<?php

declare(strict_types=1);

namespace App\DTO\Extraction;

use App\Enum\DocumentType;

/**
 * What the extractor believes the document is. Produced in the same call as
 * the extraction itself: the binary is already in the request, so asking a
 * second time would resend it and double the input tokens for no gain in
 * accuracy.
 *
 * Kept apart from the type the lawyer picked at upload. Both are persisted,
 * because when a value ends up in a court filing it must be possible to say
 * whether a human or the model decided where it came from.
 */
final readonly class DocumentClassification
{
    /**
     * Confidence above which a detection may act on its own: replace the
     * wizard's placeholder type, and narrow the field set the extraction is
     * scored against. Below it the detection is recorded and shown, and a human
     * decides. One threshold rather than one per consumer, so a guess cannot be
     * refused as a type and trusted as a scoring basis in the same pass.
     */
    public const ADOPTION_THRESHOLD = 0.7;

    /**
     * @param float $confidence 0..1
     * @param ?string $subtype free-form refinement the enum has no case for
     *                         (for example "factura storno"); never mapped to
     *                         a type, only shown and logged
     * @param ?string $rationale short justification, for the audit trail
     */
    public function __construct(
        public DocumentType $type,
        public float $confidence,
        public ?string $subtype = null,
        public ?string $rationale = null,
    ) {}

    /**
     * Whether this detection is confident enough to be acted on without a
     * human confirming it first.
     */
    public function isActionable(): bool
    {
        return $this->confidence > self::ADOPTION_THRESHOLD;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'confidence' => $this->confidence,
            'subtype' => $this->subtype,
            'rationale' => $this->rationale,
        ];
    }

    /**
     * Rebuilds from a persisted payload. Returns null for anything that is not
     * a recognisable classification, so reading a document extracted before
     * classification existed simply yields none.
     *
     * @param mixed $raw
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }
        $type = is_string($raw['type'] ?? null) ? DocumentType::tryFrom($raw['type']) : null;
        if ($type === null) {
            return null;
        }
        $confidence = is_numeric($raw['confidence'] ?? null) ? (float) $raw['confidence'] : 0.0;

        return new self(
            type: $type,
            confidence: max(0.0, min(1.0, $confidence)),
            subtype: is_string($raw['subtype'] ?? null) && $raw['subtype'] !== '' ? $raw['subtype'] : null,
            rationale: is_string($raw['rationale'] ?? null) && $raw['rationale'] !== '' ? $raw['rationale'] : null,
        );
    }
}
