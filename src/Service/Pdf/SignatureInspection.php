<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * What PdfSignatureInspector found. INTACT means a signature is present and the
 * signed bytes were not changed; it says nothing about the certificate's chain,
 * its qualified status or its revocation, which the platform does not check.
 */
final readonly class SignatureInspection
{
    public const NONE = 'NONE';
    public const INTACT = 'INTACT';
    public const DAMAGED = 'DAMAGED';
    public const UNSUPPORTED = 'UNSUPPORTED';

    /**
     * @param list<string> $warnings translation keys
     */
    public function __construct(
        public string $outcome,
        public int $signatureCount = 0,
        public ?string $subFilter = null,
        public ?int $coveredLength = null,
        public ?string $signerCn = null,
        public ?string $issuerCn = null,
        public ?\DateTimeImmutable $notBefore = null,
        public ?\DateTimeImmutable $notAfter = null,
        public ?\DateTimeImmutable $signingTime = null,
        public ?string $signingTimeSource = null,
        public array $warnings = [],
    ) {}

    public function isIntact(): bool
    {
        return $this->outcome === self::INTACT;
    }

    /**
     * Only the certificate's common names are kept: the full subject of a Romanian
     * qualified certificate often carries the holder's personal numeric code.
     *
     * @return array<string, mixed>
     */
    public function toReport(): array
    {
        return [
            'subFilter' => $this->subFilter,
            'signatureCount' => $this->signatureCount,
            'signerCn' => $this->signerCn,
            'issuerCn' => $this->issuerCn,
            'notBefore' => $this->notBefore?->format(\DATE_ATOM),
            'notAfter' => $this->notAfter?->format(\DATE_ATOM),
            'signingTime' => $this->signingTime?->format(\DATE_ATOM),
            'signingTimeSource' => $this->signingTimeSource,
            'warnings' => $this->warnings,
        ];
    }
}
