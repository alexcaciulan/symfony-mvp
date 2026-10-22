<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Enum\InboundAuthVerdict;

/**
 * A technical check of the sender, not an attestation of the court. Only the
 * topmost Authentication-Results counts, and only when our own receiving server
 * wrote it (its authserv-id from config): any copy below it, or one with another
 * id, came with the message and proves nothing. TRUSTED_PASS needs DMARC to pass
 * for a just.ro domain, which shows the domain and nothing about which registry
 * or whether the message is an act of the court.
 */
final class InboundAuthVerifier
{
    public function __construct(
        private readonly string $inboundAuthservId,
    ) {}

    public function verify(ParsedInboundMail $mail): InboundAuthVerdict
    {
        $top = $mail->authenticationResults[0] ?? null;
        if ($top === null) {
            return InboundAuthVerdict::ABSENT;
        }
        $ours = strtolower(trim($this->inboundAuthservId));
        if ($ours === '' || self::authservId($top) !== $ours) {
            return InboundAuthVerdict::FAIL;
        }
        $dmarc = preg_match('/\bdmarc\s*=\s*([a-z]+)/i', $top, $m) === 1 ? strtolower($m[1]) : null;
        $fromDomain = preg_match('/\bheader\.from\s*=\s*"?([^\s;"]+)/i', $top, $d) === 1 ? strtolower(rtrim($d[1], '.')) : '';
        $justRo = $fromDomain === 'just.ro' || str_ends_with($fromDomain, '.just.ro');

        return $dmarc === 'pass' && $justRo ? InboundAuthVerdict::TRUSTED_PASS : InboundAuthVerdict::FAIL;
    }

    private static function authservId(string $value): string
    {
        $first = trim(explode(';', $value, 2)[0]);

        // "authserv-id [version]": the version is optional.
        return strtolower((string) preg_split('/\s+/', $first)[0]);
    }
}
