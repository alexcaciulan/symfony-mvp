<?php

declare(strict_types=1);

namespace App\Service\Inbound;

/**
 * Reads a delivery report: the RFC 3464 per-recipient fields when the report is
 * standard, otherwise the status codes and the address a bounce text mentions.
 * Pure: it gets the report body and the plain text, nothing else.
 */
final class DsnParser
{
    /**
     * @param ?string $deliveryStatus the message/delivery-status part, if any
     * @param string  $text           the readable body, for non-standard bounces
     * @param bool    $looksLikeBounce whether the headers say it is a bounce (null sender, report type, daemon)
     */
    public function parse(?string $deliveryStatus, string $text, bool $looksLikeBounce): ?DsnResult
    {
        if ($deliveryStatus !== null && trim($deliveryStatus) !== '') {
            $result = $this->standard($deliveryStatus);
            if ($result !== null) {
                return $result;
            }
        }

        return $looksLikeBounce ? $this->fallback($text) : null;
    }

    private function standard(string $report): ?DsnResult
    {
        $normalized = str_replace("\r\n", "\n", $report);
        // Unfold continuation lines before reading the fields.
        $normalized = (string) preg_replace("/\n[ \t]+/", ' ', $normalized);
        $groups = preg_split("/\n\s*\n/", trim($normalized)) ?: [];
        // The first group is per-message; the recipients follow. The first recipient that
        // failed wins over one delivered, so a report on several recipients is not misread.
        $best = null;
        foreach ($groups as $group) {
            $fields = self::fields($group);
            if (!isset($fields['action']) && !isset($fields['status'])) {
                continue;
            }
            $diagnostic = $fields['diagnostic-code'] ?? null;
            $result = new DsnResult(
                isset($fields['action']) ? strtolower(trim($fields['action'])) : null,
                self::status($fields['status'] ?? null) ?? self::status($diagnostic),
                self::code($diagnostic),
                self::address($fields['final-recipient'] ?? $fields['original-recipient'] ?? null),
                $diagnostic !== null ? self::clean(preg_replace('/^\s*smtp;\s*/i', '', $diagnostic) ?? $diagnostic) : null,
                true,
            );
            if ($best === null || ($best->action !== 'failed' && $result->action === 'failed')) {
                $best = $result;
            }
        }

        return $best;
    }

    private function fallback(string $text): ?DsnResult
    {
        $status = preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', $text, $m) === 1 ? $m[1] : null;
        $code = preg_match('/\b([45]\d\d)\b/', $text, $c) === 1 ? (int) $c[1] : null;
        if ($status === null && $code === null) {
            return null;
        }
        $recipient = null;
        preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $all);
        foreach ($all[0] as $address) {
            // The report's own addresses are never the recipient that failed.
            if (preg_match('/^(bounces\+|mailer-daemon@|postmaster@)/i', $address) !== 1) {
                $recipient = strtolower($address);
                break;
            }
        }
        $line = null;
        foreach (preg_split('/\R/', $text) ?: [] as $candidate) {
            if (($status !== null && str_contains($candidate, $status)) || ($code !== null && str_contains($candidate, (string) $code))) {
                $line = $candidate;
                break;
            }
        }
        $class = $status !== null ? (int) $status[0] : intdiv((int) $code, 100);

        return new DsnResult($class === 4 ? 'delayed' : 'failed', $status, $code, $recipient, $line !== null ? self::clean($line) : null, false);
    }

    /** @return array<string, string> */
    private static function fields(string $group): array
    {
        $fields = [];
        foreach (explode("\n", $group) as $line) {
            if (preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m) === 1) {
                $fields[strtolower($m[1])] ??= trim($m[2]);
            }
        }

        return $fields;
    }

    private static function status(?string $value): ?string
    {
        return $value !== null && preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', $value, $m) === 1 ? $m[1] : null;
    }

    private static function code(?string $diagnostic): ?int
    {
        return $diagnostic !== null && preg_match('/(?:^|;)\s*([245]\d\d)\b/', $diagnostic, $m) === 1 ? (int) $m[1] : null;
    }

    private static function address(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) preg_replace('/^\s*[A-Za-z0-9-]+\s*;\s*/', '', $value);

        return strtolower(trim($value, " <>\t"));
    }

    private static function clean(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $value)), 0, 1000);
    }
}
