<?php

declare(strict_types=1);

namespace App\DTO\Case;

use Symfony\Component\Validator\Constraints as Assert;

/** The lawyer's internal notes on a case, as the case page posts them. */
class CaseNotesInput
{
    public const MAX_LENGTH = 5000;

    public function __construct(
        #[Assert\Length(max: self::MAX_LENGTH, maxMessage: 'case.notes.error.too_long')]
        public ?string $notes = null,
    ) {}
}
