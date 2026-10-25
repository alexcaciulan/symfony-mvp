<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Enum\InboundClassification;
use App\Service\Filing\SmtpFailureClassifier;
use App\Service\Filing\SmtpFailureKind;

/**
 * Decides what a message is from its reports and headers, never from who it claims
 * to be: a delivery report by its status (with the outgoing SMTP rules), a read
 * receipt by its disposition part, an automatic answer by its markers (a receipt
 * only when it also says it acknowledges receipt, and an out-of-office reply only
 * with an explicit confirmation phrase), a person's answer by threading
 * on one of our messages. Our own forward coming back is never a court message.
 */
final class InboundMailClassifier
{
    private const AUTO_SUBJECT = '/^\s*(automatic reply|auto(matic)?[- ]?reply|autoreply|out of office|r[aă]spuns automat|absent)\b/iu';

    /**
     * @param list<string> $receiptPhrases        explicit confirmation phrases, compared without diacritics and case
     * @param list<string> $receiptGeneralPhrases phrases an out-of-office reply may also contain
     */
    public function __construct(
        private readonly SmtpFailureClassifier $smtpClassifier,
        private readonly string $courtFilingBounceDomain,
        private readonly array $receiptPhrases,
        private readonly array $receiptGeneralPhrases,
    ) {}

    public function classify(ParsedInboundMail $mail): InboundClassification
    {
        if ($mail->marker('x-lexrecovery-forward') !== null) {
            return InboundClassification::AUTO_REPLY_OTHER;
        }
        if ($this->isSpam($mail)) {
            return InboundClassification::SPAM;
        }

        $dsn = $mail->dsn;
        if ($dsn !== null) {
            return $this->bounceClass($dsn);
        }
        if ($mail->mdn !== null) {
            return InboundClassification::READ_RECEIPT;
        }

        if ($this->isAutomatic($mail)) {
            $phrases = $this->isAutoReply($mail) ? $this->receiptPhrases : [...$this->receiptPhrases, ...$this->receiptGeneralPhrases];

            return $this->mentions($mail, $phrases) ? InboundClassification::RECEIPT : InboundClassification::AUTO_REPLY_OTHER;
        }
        if ($this->isThreadedToUs($mail)) {
            return InboundClassification::HUMAN_REPLY;
        }

        return InboundClassification::UNKNOWN;
    }

    public function bounceClass(DsnResult $dsn): InboundClassification
    {
        if ($dsn->isDelayed()) {
            return InboundClassification::DELAYED;
        }

        return match ($dsn->statusClass()) {
            4 => InboundClassification::BOUNCE_TEMPORARY,
            // A report that failed with 5.x.x is final even for a full mailbox (5.2.2):
            // the remote server gave up and nothing retries it.
            5 => $this->smtpClassifier->classifyStatus($dsn->smtpCode, $dsn->status, 'dsn', true)->kind === SmtpFailureKind::SIZE
                ? InboundClassification::BOUNCE_SIZE
                : InboundClassification::BOUNCE_PERMANENT,
            // A report of successful delivery or relay has nothing to act on.
            default => InboundClassification::UNKNOWN,
        };
    }

    public function isThreadedToUs(ParsedInboundMail $mail): bool
    {
        $ids = $mail->threadIds();
        if ($mail->mdn?->originalMessageId !== null) {
            $ids[] = $mail->mdn->originalMessageId;
        }
        foreach ($ids as $id) {
            if (self::isOurMessageId($id, $this->courtFilingBounceDomain)) {
                return true;
            }
        }

        return false;
    }

    public static function isOurMessageId(string $id, string $domain): bool
    {
        return preg_match('/^<filing\.[a-z2-7]{26}\.\d{1,3}@' . preg_quote(strtolower($domain), '/') . '>$/', strtolower($id)) === 1;
    }

    private function isSpam(ParsedInboundMail $mail): bool
    {
        $flag = strtolower(trim((string) $mail->marker('x-spam-flag')));
        $status = strtolower(trim((string) $mail->marker('x-spam-status')));

        return $flag === 'yes' || str_starts_with($status, 'yes');
    }

    private function isAutomatic(ParsedInboundMail $mail): bool
    {
        $autoSubmitted = strtolower(trim((string) $mail->marker('auto-submitted')));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }
        $precedence = strtolower(trim((string) $mail->marker('precedence')));
        if (in_array($precedence, ['auto_reply', 'bulk', 'junk', 'list'], true)) {
            return true;
        }

        return $mail->marker('x-auto-response-suppress') !== null
            || $mail->marker('x-autoreply') !== null
            || $mail->marker('x-autorespond') !== null
            || preg_match(self::AUTO_SUBJECT, (string) $mail->subject) === 1;
    }

    /** An out-of-office or other personal auto-responder, by its subject or RFC 3834 marker. */
    private function isAutoReply(ParsedInboundMail $mail): bool
    {
        return strtolower(trim((string) $mail->marker('auto-submitted'))) === 'auto-replied'
            || preg_match(self::AUTO_SUBJECT, (string) $mail->subject) === 1;
    }

    /** @param list<string> $phrases */
    private function mentions(ParsedInboundMail $mail, array $phrases): bool
    {
        $haystack = self::fold((string) $mail->subject . "\n" . mb_substr($mail->text, 0, 4000));
        foreach ($phrases as $phrase) {
            if (str_contains($haystack, self::fold($phrase))) {
                return true;
            }
        }

        return false;
    }

    private static function fold(string $text): string
    {
        $text = mb_strtolower($text);

        return strtr($text, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    }
}
