<?php

declare(strict_types=1);

namespace App\Service\Extraction;

/**
 * Whether two documents state the same thing in different spellings.
 *
 * The conflicts panel is only useful while what it shows is a real
 * disagreement. A seat written "str. Bihorului, nr.10" in the contract and
 * "Strada Bihorului, Nr. 10, C.P. 400295" on the invoice, or an administrator
 * named "Marchis Bogdan" in one and "Bogdan Marius Marchiș" in the other, is
 * one fact; asking the lawyer to pick between them trains them to click through
 * the panel that also carries contradicting tax numbers.
 *
 * Every rule here errs towards keeping a conflict: two values are the same only
 * when nothing that tells two places or two people apart differs between them.
 */
final class ConflictValueEquivalence
{
    /** Fields naming a natural person. */
    private const PERSON_FIELDS = ['legalRepresentative', 'administrator'];

    /** Fields naming an administrative unit. */
    private const UNIT_FIELDS = ['county', 'locality'];

    /**
     * Spellings of the same street-address word, reduced to one form.
     *
     * @var array<string, string>
     */
    private const ADDRESS_WORDS = [
        'STRADA' => 'STR', 'STR' => 'STR',
        'BULEVARDUL' => 'BD', 'BULEVARD' => 'BD', 'BDUL' => 'BD', 'BD' => 'BD', 'BLD' => 'BD', 'BLVD' => 'BD',
        'SOSEAUA' => 'SOS', 'SOS' => 'SOS',
        'CALEA' => 'CALEA', 'CAL' => 'CALEA',
        'ALEEA' => 'ALEEA', 'AL' => 'ALEEA',
        'PIATA' => 'PTA', 'PTA' => 'PTA',
        'INTRAREA' => 'INTR', 'INTR' => 'INTR',
        'SPLAIUL' => 'SPL', 'SPL' => 'SPL',
        'NUMARUL' => 'NR', 'NUMAR' => 'NR', 'NR' => 'NR',
        'BLOCUL' => 'BL', 'BLOC' => 'BL', 'BL' => 'BL',
        'SCARA' => 'SC', 'SC' => 'SC',
        'ETAJUL' => 'ET', 'ETAJ' => 'ET', 'ET' => 'ET',
        'APARTAMENTUL' => 'AP', 'APARTAMENT' => 'AP', 'AP' => 'AP', 'APT' => 'AP',
        'CLADIREA' => 'CLADIRE', 'CLADIRE' => 'CLADIRE', 'CORP' => 'CLADIRE',
        'BIROUL' => 'BIROU', 'BIROU' => 'BIROU',
    ];

    /**
     * Words that only say what kind of unit or code follows. Dropped, because
     * "Municipiul Cluj-Napoca" and "Cluj-Napoca" are one place, and the postal
     * code is printed on some documents and not on others.
     */
    private const UNIT_PREFIXES = ['MUNICIPIUL', 'MUN', 'ORASUL', 'ORAS', 'ORS', 'COMUNA', 'COM', 'SATUL', 'SAT', 'LOC', 'LOCALITATEA', 'JUDETUL', 'JUDET', 'JUD', 'CP', 'COD', 'POSTAL'];

    public function same(string $field, mixed $a, mixed $b): bool
    {
        if (!is_string($a) || !is_string($b)) {
            return false;
        }

        return match (true) {
            in_array($field, self::PERSON_FIELDS, true) => $this->samePerson($a, $b),
            in_array($field, self::UNIT_FIELDS, true) => $this->unitKey($a) !== '' && $this->unitKey($a) === $this->unitKey($b),
            $field === 'address' => $this->sameAddress($a, $b),
            $field === 'iban' => $this->ibanKey($a) !== '' && $this->ibanKey($a) === $this->ibanKey($b),
            $field === 'name' => $this->companyKey($a) !== '' && $this->companyKey($a) === $this->companyKey($b),
            $field === 'bankName' => $this->bankKey($a) !== '' && $this->bankKey($a) === $this->bankKey($b),
            default => false,
        };
    }

    /**
     * One person when the shorter name is wholly contained in the longer one:
     * documents drop a middle name or swap the order of given and family names,
     * but they do not swap one surname for another.
     */
    private function samePerson(string $a, string $b): bool
    {
        $left = $this->words($a);
        $right = $this->words($b);
        if (count($left) < 2 || count($right) < 2) {
            return $left !== [] && $left === $right;
        }
        [$short, $long] = count($left) <= count($right) ? [$left, $right] : [$right, $left];

        return array_diff($short, $long) === [];
    }

    /**
     * One address when the shorter is contained in the longer and what the
     * longer adds carries no number: a locality or county name repeated in the
     * street line adds nothing, while a block, floor or apartment number is
     * another door.
     */
    private function sameAddress(string $a, string $b): bool
    {
        $left = $this->addressWords($a);
        $right = $this->addressWords($b);
        if ($left === [] || $right === []) {
            return false;
        }
        [$short, $long] = count($left) <= count($right) ? [$left, $right] : [$right, $left];

        $remaining = $long;
        foreach ($short as $word) {
            $at = array_search($word, $remaining, true);
            if ($at === false) {
                return false;
            }
            unset($remaining[$at]);
        }
        foreach ($remaining as $extra) {
            if (preg_match('/\d/', $extra) === 1) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function addressWords(string $raw): array
    {
        $words = [];
        foreach ($this->words($raw) as $word) {
            if (in_array($word, self::UNIT_PREFIXES, true)) {
                continue;
            }
            // A Romanian postal code is six digits; it belongs to the address
            // on some documents and is missing from others.
            if (preg_match('/^\d{6}$/', $word) === 1) {
                continue;
            }
            $words[] = self::ADDRESS_WORDS[$word] ?? $word;
        }

        return $words;
    }

    private function unitKey(string $raw): string
    {
        $words = array_values(array_filter(
            $this->words($raw),
            static fn (string $w): bool => !in_array($w, self::UNIT_PREFIXES, true),
        ));
        $words = array_map(static fn (string $w): string => $w === 'SECTORUL' || $w === 'SECT' ? 'SECTOR' : $w, $words);

        return implode(' ', $words);
    }

    /**
     * A company name without its dots and the generic "SC" prefix. The legal
     * form itself stays: "ALFA SRL" and "ALFA SA" are two taxpayers.
     */
    private function companyKey(string $raw): string
    {
        $value = mb_strtoupper(trim($raw));
        $value = strtr($value, ['Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T', 'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', '.' => '']);
        $value = preg_replace('/[^A-Z0-9&]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/^SC\s+/', '', trim($value)) ?? $value;

        return trim($value);
    }

    /**
     * A bank name without punctuation, diacritics, its "SA" form or the
     * country: "ING Bank" and "ING BANK ROMANIA" are one bank.
     */
    private function bankKey(string $raw): string
    {
        $words = array_filter($this->words($raw), static fn (string $w): bool => !in_array($w, ['SA', 'S', 'A', 'ROMANIA'], true));

        return implode(' ', $words);
    }

    private function ibanKey(string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $raw) ?? '');
    }

    /**
     * Upper-case words without diacritics or punctuation. A dotted abbreviation
     * ("B-dul", "Nr.") falls apart into its letters here, which the caller then
     * reads as one word again.
     *
     * @return list<string>
     */
    private function words(string $raw): array
    {
        $value = mb_strtoupper(trim($raw));
        $value = strtr($value, ['Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T', 'Ă' => 'A', 'Â' => 'A', 'Î' => 'I']);
        // "B-DUL" and "BL." keep their letters together; "NR.10" splits.
        $value = preg_replace('/(?<=[A-Z])-(?=[A-Z])/u', '', $value) ?? $value;
        $value = preg_replace('/(?<=[A-Z])\.?(?=\d)|(?<=\d)(?=[A-Z]{2,})/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^A-Z0-9]+/u', ' ', $value) ?? $value;

        return array_values(array_filter(explode(' ', $value), static fn (string $w): bool => $w !== ''));
    }
}
