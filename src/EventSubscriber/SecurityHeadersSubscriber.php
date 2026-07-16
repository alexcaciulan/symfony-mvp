<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\CspNonceProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Emits security response headers on every main-request response. CSP uses a per-request
 * nonce (see CspNonceProvider) so the app's few inline scripts run while injected scripts
 * are blocked. `style-src` keeps 'unsafe-inline' because Tailwind/Preline emit inline
 * style attributes, which nonces cannot cover. Each header is set only if absent, so an
 * intentional per-response override still wins.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CspNonceProvider $nonceProvider,
        private readonly string $mercurePublicUrl,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 0 runs before WebDebugToolbarListener (-128), so the dev toolbar's
        // CSP handler can augment the header we set with its own nonces.
        return [KernelEvents::RESPONSE => ['onResponse', 0]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;

        $set = static function (string $name, string $value) use ($headers): void {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        };

        $set('X-Content-Type-Options', 'nosniff');
        $set('X-Frame-Options', 'DENY');
        $set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $set('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=(), usb=()');

        // HSTS only over HTTPS (isSecure() is reliable now that trusted_proxies is set),
        // so plain-HTTP local dev never gets pinned to https.
        if ($request->isSecure()) {
            $set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $set('Content-Security-Policy', $this->buildCsp());
    }

    private function buildCsp(): string
    {
        $nonce = $this->nonceProvider->getNonce();
        $connectSrc = trim("'self' " . $this->originOf($this->mercurePublicUrl));

        return implode('; ', [
            "default-src 'self'",
            // 'strict-dynamic': trust propagates from the nonced importmap entry through
            // the whole module graph, including AssetMapper's data: URI CSS-in-JS modules.
            // CSP3 browsers ignore 'self'/host allowlists here (stronger); the nonce +
            // 'self' remain as the CSP2 fallback for older engines.
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data:",
            "connect-src {$connectSrc}",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
