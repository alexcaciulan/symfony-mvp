<?php

declare(strict_types=1);

namespace App\Twig;

use App\Util\PiiMasker;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Exposes PiiMasker static helpers as Twig filters so views that display
 * extracted personal data (CNP / IBAN) can mask them at render time.
 *
 * Use case: Pas 3.0 wizard step 0 sidecard preview ("Date detectate") shows
 * the auto-filled values to the lawyer for confirmation. The actual DTO carries
 * unmasked values because Pas 3.1+ forms pre-populate the inputs that the
 * lawyer must edit — but the preview surface (rendered HTML, browser cache,
 * server access logs) gets the masked version to satisfy GDPR art. 5(1)(c)
 * minimisation + art. 32 security.
 *
 * Filters are non-throwing: null and non-string input pass through unchanged
 * so misconfigured templates degrade gracefully instead of breaking rendering.
 */
final class PiiMaskerExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('mask_cnp', $this->maskCnp(...)),
            new TwigFilter('mask_iban', $this->maskIban(...)),
        ];
    }

    public function maskCnp(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        return PiiMasker::maskCnp($value);
    }

    public function maskIban(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        return PiiMasker::maskIban($value);
    }
}
