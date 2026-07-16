<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Per-request CSP nonce, shared between the Twig `csp_nonce()` function (which stamps it
 * onto the few inline <script> tags) and SecurityHeadersSubscriber (which emits the same
 * value in the Content-Security-Policy header). Stored on the Request so both sides read
 * one consistent value per request.
 */
final class CspNonceProvider
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getNonce(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return $this->generate();
        }

        $nonce = $request->attributes->get('_csp_nonce');
        if (!\is_string($nonce)) {
            $nonce = $this->generate();
            $request->attributes->set('_csp_nonce', $nonce);
        }

        return $nonce;
    }

    private function generate(): string
    {
        return base64_encode(random_bytes(16));
    }
}
