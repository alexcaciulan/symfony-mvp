<?php

declare(strict_types=1);

namespace App\Service\Filing;

/**
 * The signed file is fine but makes its email exceed the court mailbox limit.
 * Nothing was stored. When the email carries annex volumes, splitting them again
 * with a budget smaller by $excessBytes (encoded) makes room; otherwise the file
 * itself is too large and has to be produced and signed again.
 */
final class SignedPartOverBudgetException extends \RuntimeException
{
    public function __construct(
        public readonly int $excessBytes,
        public readonly bool $rebuildable,
    ) {
        parent::__construct($rebuildable ? 'filing.upload.over_budget.text' : 'filing.upload.error.first_email_too_large');
    }

    public function messageKey(): string
    {
        return $this->getMessage();
    }
}
