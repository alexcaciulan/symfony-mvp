<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\CspNonceProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `csp_nonce()` to templates so inline <script> tags (and the importmap) can
 * carry the per-request nonce that SecurityHeadersSubscriber whitelists in the CSP.
 */
final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly CspNonceProvider $nonceProvider)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonceProvider->getNonce(...)),
        ];
    }
}
