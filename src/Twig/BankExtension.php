<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Party\RomanianBankCode;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `iban_bank` names the bank a Romanian IBAN belongs to, or null when the code
 * is unknown, so a list of accounts reads as accounts at named banks.
 */
final class BankExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('iban_bank', static fn (mixed $iban): ?string => is_string($iban) ? RomanianBankCode::bankName($iban) : null),
        ];
    }
}
