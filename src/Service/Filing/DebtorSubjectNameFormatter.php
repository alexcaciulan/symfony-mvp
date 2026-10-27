<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Enum\PersonType;

/**
 * How a debtor is named in the subject of a court email. The subject travels in
 * clear through every relay, so a natural person (also when trading as PFA, II or
 * IF) is reduced to initials, with the CUI only when one is on file; a company
 * keeps its full name. The full identification stays in the petition.
 *
 * PFA, II and IF debtors are stored as PJ, so a PJ is shortened only when its
 * name carries a marker and no company form: "Hotel Parc II SRL" is a company
 * whose name merely contains the token.
 */
final class DebtorSubjectNameFormatter
{
    /** Marker spellings, folded to ASCII lowercase, and the abbreviation kept in the subject. */
    private const MARKERS = [
        'persoana fizica autorizata' => 'PFA',
        'intreprindere individuala' => 'II',
        'intreprindere familiala' => 'IF',
        'pfa' => 'PFA',
        'ii' => 'II',
        'if' => 'IF',
    ];

    /** Company forms (folded), whose presence means the debtor is a legal entity. */
    private const COMPANY_FORM = '/(^|[\s,.\-])(s\.?r\.?l(\.?-?d)?|s\.?a|s\.?n\.?c|s\.?c\.?s|s\.?c\.?a)\.?($|[\s,.\-])/u';

    private const FOLD = [
        'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't',
    ];

    public function format(PersonType $type, string $name, ?string $cui): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        [$personal, $marker] = $this->splitMarker($name);
        if ($type === PersonType::PJ && ($marker === null || $this->hasCompanyForm($name))) {
            return $name;
        }

        $initials = '';
        foreach (preg_split('/[\s\-]+/u', $personal) ?: [] as $word) {
            $word = (string) preg_replace('/[^\p{L}]/u', '', $word);
            if ($word !== '') {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1)) . '.';
            }
        }

        $label = trim($initials . ($marker !== null ? ' ' . $marker : ''));
        $cui = $cui !== null ? trim($cui) : '';

        return $cui !== '' ? $label . ', CUI ' . $cui : $label;
    }

    /**
     * The first debtor names the case; any others are only counted.
     *
     * @param list<array{type: PersonType, name: string, cui: ?string}> $debtors in position order
     */
    public function formatDebtors(array $debtors, string $othersSuffix): string
    {
        if ($debtors === []) {
            return '';
        }
        $first = $this->format($debtors[0]['type'], $debtors[0]['name'], $debtors[0]['cui']);

        return count($debtors) > 1 ? $first . $othersSuffix : $first;
    }

    private function hasCompanyForm(string $name): bool
    {
        return preg_match(self::COMPANY_FORM, mb_strtolower(strtr($name, self::FOLD))) === 1;
    }

    /** @return array{0: string, 1: ?string} the name without the marker, and the marker */
    private function splitMarker(string $name): array
    {
        $folded = mb_strtolower(strtr($name, self::FOLD));
        foreach (self::MARKERS as $spelling => $abbreviation) {
            $pattern = '/(^|[\s,.\-])' . preg_quote($spelling, '/') . '($|[\s,.\-])/u';
            if (preg_match($pattern, $folded, $match, PREG_OFFSET_CAPTURE) === 1) {
                // Folding keeps one character per character, so offsets carry over to the original.
                $start = mb_strlen(substr($folded, 0, $match[0][1])) + mb_strlen($match[1][0]);
                $personal = mb_substr($name, 0, $start) . ' ' . mb_substr($name, $start + mb_strlen($spelling));

                return [trim((string) preg_replace('/\s+/u', ' ', trim($personal, " ,.-"))), $abbreviation];
            }
        }

        return [$name, null];
    }
}
