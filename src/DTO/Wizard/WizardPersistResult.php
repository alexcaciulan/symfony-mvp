<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Entity\LegalCase;

/**
 * What a committed wizard submission produced: the case, and whether its
 * creditor was taken from the library, said after the transaction so a
 * message about it can never outlive a rollback.
 */
final readonly class WizardPersistResult
{
    public function __construct(
        public LegalCase $case,
        public bool $creditorReused,
        public string $creditorSource,
    ) {}
}
