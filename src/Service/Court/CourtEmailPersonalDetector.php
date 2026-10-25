<?php

declare(strict_types=1);

namespace App\Service\Court;

/**
 * Flags a just.ro address shaped like a person's mailbox (firstname.lastname).
 * A heuristic: it may also catch an institutional mailbox, so its result is
 * presented as "marked automatically as nominal, to be checked", never as a fact.
 */
final class CourtEmailPersonalDetector
{
    private const INSTITUTIONAL_SEGMENTS = [
        'registratura', 'registratur', 'judecatoria', 'jud', 'tribunalul', 'trib', 'curtea',
        'sectia', 'civil', 'penal', 'profesionisti', 'info', 'relatii', 'presa', 'grefa',
        'arhiva', 'contact', 'secretariat', 'comunicare', 'office', 'dosare', 'cereri',
    ];

    private const DIACRITICS = ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't'];

    public function isNominal(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }

        $domain = substr($email, $at + 1);
        if ($domain !== 'just.ro' && !str_ends_with($domain, '.just.ro')) {
            return false;
        }

        $local = strtr(substr($email, 0, $at), self::DIACRITICS);
        if (preg_match('/^[a-z]+([._-][a-z]+){1,2}$/', $local) !== 1) {
            return false;
        }

        foreach (preg_split('/[._-]/', $local) ?: [] as $segment) {
            if (in_array($segment, self::INSTITUTIONAL_SEGMENTS, true)) {
                return false;
            }
        }

        return true;
    }
}
