<?php

declare(strict_types=1);

namespace App\Service\Party;

/**
 * The bank a Romanian IBAN belongs to, read from its four-letter bank code
 * (positions 5 to 8). The account number states the bank, so asking the lawyer
 * to pair a bank name with an account separately only invites a mismatch.
 */
final class RomanianBankCode
{
    /** @var array<string, string> */
    private const BANKS = [
        'ABNA' => 'ABN AMRO',
        'BACX' => 'UniCredit Bank',
        'BCRL' => 'Banca Comercială Română',
        'BITR' => 'Banca Italo-Română',
        'BLOM' => 'BLOM Bank France',
        'BPOS' => 'Banca Românească',
        'BRDE' => 'BRD - Groupe Société Générale',
        'BREL' => 'Libra Internet Bank',
        'BRMA' => 'Banca Românească',
        'BTRL' => 'Banca Transilvania',
        'BUCU' => 'Alpha Bank România',
        'CARP' => 'Patria Bank',
        'CECE' => 'CEC Bank',
        'CITI' => 'Citibank Europe',
        'CRCO' => 'Banca Centrală Cooperatistă CREDITCOOP',
        'DAFB' => 'Banca Comercială Feroviara',
        'EGNA' => 'Vista Bank',
        'EXIM' => 'EximBank',
        'FNNB' => 'Credit Europe Bank',
        'INGB' => 'ING Bank',
        'MIND' => 'ProCredit Bank',
        'MIRO' => 'ProCredit Bank',
        'NBOR' => 'Banca Națională a României',
        'OTPV' => 'OTP Bank',
        'PIRB' => 'First Bank',
        'PORL' => 'Porsche Bank',
        'RNCB' => 'Banca Comercială Română',
        'RZBR' => 'Raiffeisen Bank',
        'TREZ' => 'Trezoreria Statului',
        'UGBI' => 'Garanti BBVA',
        'WBAN' => 'Intesa Sanpaolo Bank',
    ];

    /**
     * A word every way of writing the bank's name contains, so "BCR TG NEAMT"
     * and "Banca Comerciala Romana" both read as RNCB.
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        'ABNA' => ['ABN'],
        'BACX' => ['UNICREDIT'],
        'BCRL' => ['BCR', 'COMERCIALA ROMANA'],
        'BITR' => ['ITALO'],
        'BLOM' => ['BLOM'],
        'BPOS' => ['ROMANEASCA'],
        'BRDE' => ['BRD'],
        'BREL' => ['LIBRA'],
        'BRMA' => ['ROMANEASCA'],
        'BTRL' => ['TRANSILVANIA'],
        'BUCU' => ['ALPHA'],
        'CARP' => ['PATRIA'],
        'CECE' => ['CEC'],
        'CITI' => ['CITI'],
        'CRCO' => ['CREDITCOOP'],
        'DAFB' => ['FEROVIARA'],
        'EGNA' => ['VISTA'],
        'EXIM' => ['EXIM'],
        'FNNB' => ['CREDIT EUROPE'],
        'INGB' => ['ING'],
        'MIND' => ['PROCREDIT'],
        'MIRO' => ['PROCREDIT'],
        'NBOR' => ['NATIONALA'],
        'OTPV' => ['OTP'],
        'PIRB' => ['FIRST'],
        'PORL' => ['PORSCHE'],
        'RNCB' => ['BCR', 'COMERCIALA ROMANA'],
        'RZBR' => ['RAIFFEISEN'],
        'TREZ' => ['TREZOR'],
        'UGBI' => ['GARANTI'],
        'WBAN' => ['INTESA'],
    ];

    /**
     * Whether a bank name as typed or read names the bank the IBAN belongs to.
     * True when the IBAN's bank is unknown: nothing then says the two differ.
     */
    public static function nameMatches(?string $bankName, string $iban): bool
    {
        $code = self::code($iban);
        if ($code === null || !isset(self::KEYWORDS[$code])) {
            return true;
        }
        if ($bankName === null || trim($bankName) === '') {
            return false;
        }
        $haystack = ' ' . preg_replace('/[^A-Z]+/', ' ', strtoupper(strtr($bankName, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T']))) . ' ';
        foreach (self::KEYWORDS[$code] as $keyword) {
            // Short names (ING, CEC, BCR) only as whole words; longer ones also
            // as the start of a word, so "Trezoreria" reads as TREZOR.
            if (str_contains($haystack, ' ' . $keyword . ' ')
                || (strlen($keyword) >= 5 && str_contains($haystack, ' ' . $keyword))) {
                return true;
            }
        }

        return false;
    }

    public static function bankName(?string $iban): ?string
    {
        if ($iban === null) {
            return null;
        }
        $code = self::code($iban);

        return $code !== null ? (self::BANKS[$code] ?? null) : null;
    }

    private static function code(string $iban): ?string
    {
        $compact = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $iban) ?? '');
        if (!str_starts_with($compact, 'RO') || strlen($compact) !== 24) {
            return null;
        }

        return substr($compact, 4, 4);
    }
}
