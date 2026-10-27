<?php

declare(strict_types=1);

namespace App\Service\Filing;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Decides what a failed SMTP hand-off means for a court email, from the reply
 * code, the enhanced status (RFC 3463) and the SMTP dialogue the transport logged.
 *
 * Only a rejection of the court's own recipient as unknown marks the court address
 * invalid: the address is shared between lawyers and rests on their declarations,
 * so a policy, spam, routing or sender refusal must never take it out of use.
 */
final class SmtpFailureClassifier
{
    public const STAGE_CONNECT = 'connect';
    public const STAGE_GREETING = 'greeting';
    public const STAGE_TLS = 'starttls';
    public const STAGE_AUTH = 'auth';
    public const STAGE_MAIL_FROM = 'mail_from';
    public const STAGE_RCPT = 'rcpt';
    public const STAGE_DATA = 'data';
    public const STAGE_MESSAGE = 'message';
    public const STAGE_END_OF_DATA = 'end_of_data';

    private const MAX_SERVER_TEXT = 200;

    public function classify(\Throwable $e, string $courtRecipient): SmtpFailure
    {
        $debug = $e instanceof TransportExceptionInterface ? $e->getDebug() : '';
        [$stage, $rcpt] = self::stage($debug);
        $code = $e->getCode() >= 200 && $e->getCode() < 600 ? (int) $e->getCode() : null;
        $serverText = self::serverText($e->getMessage());
        $enhanced = preg_match('/\b([245])\.(\d{1,3})\.(\d{1,3})\b/', $serverText, $m) === 1 ? $m[0] : null;
        $courtRcpt = $stage === self::STAGE_RCPT && $rcpt !== null && strcasecmp($rcpt, $courtRecipient) === 0;

        $kind = $this->kind($code, $enhanced, $stage);
        $invalidates = $kind === SmtpFailureKind::PERMANENT && $courtRcpt && self::rejectsRecipient($code, $enhanced);

        $safe = sprintf('SMTP %s%s at %s', $code ?? '-', $enhanced !== null ? ' ' . $enhanced : '', $stage);
        if ($serverText !== '') {
            $safe .= ': ' . $serverText;
        }

        return new SmtpFailure($kind, $code, $enhanced, $stage, $invalidates, mb_substr($safe, 0, 500));
    }

    /**
     * The same rules for a code and an enhanced status known without an SMTP
     * dialogue, as in a delivery report received later. The address is invalidated
     * only for the court's own recipient rejected as unknown, never for a policy
     * (5.7.x), mailbox (5.2.x) or size refusal.
     */
    public function classifyStatus(?int $code, ?string $enhanced, string $stage, bool $isCourtRecipient, string $safeMessage = ''): SmtpFailure
    {
        $classCode = $code;
        if ($classCode === null && $enhanced !== null && preg_match('/^([245])\./', $enhanced, $m) === 1) {
            // A report gives the enhanced status only: its class stands in for the reply
            // code when deciding, and is never recorded as a code the server sent.
            $classCode = ((int) $m[1]) * 100;
        }
        $kind = $this->kind($classCode, $enhanced, $stage);
        $invalidates = $kind === SmtpFailureKind::PERMANENT && $isCourtRecipient && self::rejectsRecipient($classCode, $enhanced);

        return new SmtpFailure($kind, $code, $enhanced, $stage, $invalidates, mb_substr($safeMessage, 0, 500));
    }

    private function kind(?int $code, ?string $enhanced, string $stage): SmtpFailureKind
    {
        if ($code === null) {
            // No reply code: the connection broke or timed out. Once the final dot was
            // written the server may have queued the message, so it is never retried.
            return $stage === self::STAGE_END_OF_DATA ? SmtpFailureKind::UNCERTAIN : SmtpFailureKind::TEMPORARY;
        }
        if ($code >= 400 && $code < 500) {
            return SmtpFailureKind::TEMPORARY;
        }
        if ($code < 500) {
            // An unexpected 2xx/3xx reply is a broken dialogue; after the dot it may have been taken.
            return $stage === self::STAGE_END_OF_DATA ? SmtpFailureKind::UNCERTAIN : SmtpFailureKind::TEMPORARY;
        }
        // 5.2.2 is a full mailbox, which clears without anyone's action.
        if ($enhanced === '5.2.2') {
            return SmtpFailureKind::TEMPORARY;
        }
        if ($code === 552 || in_array($enhanced, ['5.3.4', '5.2.3'], true)) {
            return SmtpFailureKind::SIZE;
        }

        return SmtpFailureKind::PERMANENT;
    }

    /** Enhanced statuses (RFC 3463) that name the recipient mailbox or its domain as unknown. */
    public static function isUnknownMailboxStatus(?string $enhanced): bool
    {
        return in_array($enhanced, ['5.1.1', '5.1.2', '5.1.3', '5.4.4'], true);
    }

    /**
     * "Unknown or invalid recipient": enhanced 5.1.1 to 5.1.3, 5.4.4 (no route to the
     * domain), or a bare 550/551/553 without an enhanced status.
     */
    private static function rejectsRecipient(?int $code, ?string $enhanced): bool
    {
        if ($enhanced !== null) {
            return self::isUnknownMailboxStatus($enhanced);
        }

        return in_array($code, [550, 551, 553], true);
    }

    /**
     * The last client command before the failure, from the transport's dialogue log
     * ("> COMMAND" lines written by the client, "< reply" lines read from the server).
     *
     * @return array{0: string, 1: ?string} stage and, for RCPT, the recipient
     */
    private static function stage(string $debug): array
    {
        $stage = self::STAGE_CONNECT;
        $rcpt = null;
        $seen354 = false;
        foreach (preg_split('/\R/', $debug) ?: [] as $line) {
            if (preg_match('/^(?:\[[^\]]*\]\s*)?<\s*(\d{3})/', $line, $m) === 1) {
                if ($stage === self::STAGE_CONNECT) {
                    $stage = self::STAGE_GREETING;
                }
                if ($m[1] === '354' && $stage === self::STAGE_DATA) {
                    $seen354 = true;
                    $stage = self::STAGE_MESSAGE;
                }
                continue;
            }
            if (preg_match('/^(?:\[[^\]]*\]\s*)?>\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $command = trim($m[1]);
            $upper = strtoupper($command);
            $rcpt = null;
            $stage = match (true) {
                $command === '.' && $seen354 => self::STAGE_END_OF_DATA,
                str_starts_with($upper, 'EHLO'), str_starts_with($upper, 'HELO') => self::STAGE_GREETING,
                str_starts_with($upper, 'STARTTLS') => self::STAGE_TLS,
                str_starts_with($upper, 'AUTH') => self::STAGE_AUTH,
                str_starts_with($upper, 'MAIL FROM') => self::STAGE_MAIL_FROM,
                str_starts_with($upper, 'RCPT TO') => self::STAGE_RCPT,
                $upper === 'DATA' => self::STAGE_DATA,
                default => $stage,
            };
            if ($stage === self::STAGE_RCPT && preg_match('/<([^>]*)>/', $command, $r) === 1) {
                $rcpt = $r[1];
            }
            if ($stage !== self::STAGE_MESSAGE && $stage !== self::STAGE_END_OF_DATA) {
                $seen354 = false;
            }
        }

        return [$stage, $rcpt];
    }

    /** The server's own words from the exception, shortened and without email addresses. */
    private static function serverText(string $message): string
    {
        $text = preg_match('/with message "(.*)"\.?$/s', $message, $m) === 1 ? $m[1] : $message;
        $text = (string) preg_replace('/<?[^\s<>@"]+@[^\s<>"]+>?/', '[address]', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_substr($text, 0, self::MAX_SERVER_TEXT);
    }
}
