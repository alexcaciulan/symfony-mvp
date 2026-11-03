<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\Entity\ClaimItem;

/**
 * A claim edit the rules do not allow, said in a key the page translates. The
 * position the refusal is about, when there is one (a duplicate the lawyer
 * can reinstate instead), travels with it.
 */
final class ClaimEditRefused extends \DomainException
{
    /** @param array<string, string> $params */
    public function __construct(
        public readonly string $reasonCode,
        public readonly array $params = [],
        public readonly ?ClaimItem $existingItem = null,
    ) {
        parent::__construct('Claim edit refused: ' . $reasonCode);
    }

    public function messageKey(): string
    {
        return 'case.claim.edit.error.' . $this->reasonCode;
    }
}
