<?php

namespace App\Service\Court;

final class LocalityNormalizer
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = preg_replace('/\s+/u', ' ', trim($value));
        if ($trimmed === '' || $trimmed === null) {
            return null;
        }

        $decomposed = \Normalizer::normalize($trimmed, \Normalizer::FORM_D);
        if ($decomposed === false) {
            $decomposed = $trimmed;
        }

        $stripped = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed;

        return mb_strtolower($stripped);
    }
}
