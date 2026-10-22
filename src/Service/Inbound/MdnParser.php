<?php

declare(strict_types=1);

namespace App\Service\Inbound;

/** Reads the disposition-notification part of a read receipt (RFC 8098). */
final class MdnParser
{
    public function parse(?string $dispositionNotification): ?MdnResult
    {
        if ($dispositionNotification === null || trim($dispositionNotification) === '') {
            return null;
        }
        $text = (string) preg_replace("/\r?\n[ \t]+/", ' ', str_replace("\r\n", "\n", $dispositionNotification));
        $fields = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m) === 1) {
                $fields[strtolower($m[1])] ??= trim($m[2]);
            }
        }
        if (!isset($fields['disposition']) && !isset($fields['original-message-id'])) {
            return null;
        }
        $original = isset($fields['original-message-id']) && preg_match('/<[^>]+>/', $fields['original-message-id'], $m) === 1 ? $m[0] : null;
        $recipient = isset($fields['final-recipient']) ? strtolower(trim((string) preg_replace('/^\s*[A-Za-z0-9-]+\s*;\s*/', '', $fields['final-recipient']), ' <>')) : null;

        return new MdnResult($fields['disposition'] ?? null, $original, $recipient);
    }
}
