<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `percent_number` writes a contractual percentage the way the contract does:
 * "10" and "7,5", not "10,00", and never rounded to "8" for 7.5.
 */
final class PercentExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('percent_number', self::format(...)),
        ];
    }

    public static function format(mixed $value): string
    {
        $formatted = number_format((float) $value, 2, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',');
    }
}
