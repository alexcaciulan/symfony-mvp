<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether the electronic registry (registratura.rejust.ro) can take the judicial
 * stamp duty for a case yet. The platform sends the petition to the court by email,
 * so the lawyer never fills the registry's new-case form and cannot pay there. The
 * only registry form that applies is payment in an existing case, which needs the
 * file number the court assigns on registration.
 *
 * The two states without a URL are told apart only for the wording shown to the
 * lawyer: a petition not sent yet, and one already with the court but unnumbered.
 */
enum RejustStampDutyForm: string
{
    case NOT_FILED = 'NOT_FILED';
    case AWAITING_CASE_NUMBER = 'AWAITING_CASE_NUMBER';
    case EXISTING_CASE = 'EXISTING_CASE';

    public function url(): ?string
    {
        return match ($this) {
            self::EXISTING_CASE => 'https://registratura.rejust.ro/plata-taxei-judiciare-de-timbru-intr-un-dosar-existent',
            self::NOT_FILED, self::AWAITING_CASE_NUMBER => null,
        };
    }
}
