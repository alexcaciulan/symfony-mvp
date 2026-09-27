<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which form on the electronic registry (registratura.rejust.ro) collects the
 * judicial stamp duty for a case. The registry has two and they are not
 * interchangeable: the new-case form takes the duty in the same step as the
 * petition, the existing-case form needs the file number the court assigns on
 * registration.
 *
 * Between them there is a real gap. A petition already filed but still without a
 * number fits neither form, and pointing it at the new-case form would have the
 * lawyer register the same petition a second time, so that state carries no URL on
 * purpose.
 */
enum RejustStampDutyForm: string
{
    case NEW_CASE = 'NEW_CASE';
    case EXISTING_CASE = 'EXISTING_CASE';
    case AWAITING_CASE_NUMBER = 'AWAITING_CASE_NUMBER';

    public function url(): ?string
    {
        return match ($this) {
            self::NEW_CASE => 'https://registratura.rejust.ro/inregistreaza-un-dosar-nou-pe-rolul-instantei-de-judecata',
            self::EXISTING_CASE => 'https://registratura.rejust.ro/plata-taxei-judiciare-de-timbru-intr-un-dosar-existent',
            self::AWAITING_CASE_NUMBER => null,
        };
    }
}
