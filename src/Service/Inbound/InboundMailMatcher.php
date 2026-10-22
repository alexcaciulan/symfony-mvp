<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Enum\InboundAuthVerdict;
use App\Enum\InboundMatchConfidence;
use App\Enum\InboundMatchMethod;
use App\Repository\FilingEmailPartRepository;
use App\Repository\FilingSubmissionRepository;
use Psr\Clock\ClockInterface;

/**
 * Ties a message to one of our court emails. CERTAIN only through the secret VERP
 * token of a part, or through a reply on one of our Message-IDs from an
 * authenticated just.ro sender. A report quoting our headers, an unauthenticated
 * reply and the sender-domain heuristic are PROBABLE at most and always go to
 * review: a wrong attribution could undo a filing or show one client's mail to
 * another lawyer. No side effects beyond the lookups.
 */
final class InboundMailMatcher
{
    public const HEURISTIC_WINDOW_DAYS = 14;

    public function __construct(
        private readonly FilingEmailPartRepository $parts,
        private readonly FilingSubmissionRepository $submissions,
        private readonly ClockInterface $clock,
        private readonly string $courtFilingBounceDomain,
    ) {}

    public function match(ParsedInboundMail $mail, InboundAuthVerdict $auth): ?InboundMatch
    {
        $domain = strtolower($this->courtFilingBounceDomain);

        $verp = '/^bounces\+([0-9a-f]{24})@' . preg_quote($domain, '/') . '$/';
        foreach ([...$mail->envelopeRecipients, ...$mail->to, ...$mail->cc] as $recipient) {
            if (preg_match($verp, strtolower($recipient), $m) === 1) {
                $part = $this->parts->findOneByVerpToken($m[1]);
                if ($part !== null) {
                    return new InboundMatch($part, $part->getSubmission(), InboundMatchMethod::VERP, InboundMatchConfidence::CERTAIN);
                }
            }
        }

        $threadIds = $mail->threadIds();
        if ($mail->mdn?->originalMessageId !== null) {
            $threadIds[] = $mail->mdn->originalMessageId;
        }
        foreach ($threadIds as $id) {
            if (!InboundMailClassifier::isOurMessageId($id, $domain)) {
                continue;
            }
            $part = $this->parts->findOneByMessageId($id);
            if ($part !== null) {
                $confidence = $auth === InboundAuthVerdict::TRUSTED_PASS ? InboundMatchConfidence::CERTAIN : InboundMatchConfidence::PROBABLE;

                return new InboundMatch($part, $part->getSubmission(), InboundMatchMethod::IN_REPLY_TO, $confidence);
            }
        }

        if ($mail->embeddedMessageId !== null && InboundMailClassifier::isOurMessageId($mail->embeddedMessageId, $domain)) {
            $part = $this->parts->findOneByMessageId($mail->embeddedMessageId);
            if ($part !== null) {
                return new InboundMatch($part, $part->getSubmission(), InboundMatchMethod::EMBEDDED_HEADERS, InboundMatchConfidence::PROBABLE);
            }
        }
        if ($mail->embeddedRef !== null) {
            $submission = $this->submissions->findOneByToken($mail->embeddedRef);
            if ($submission !== null) {
                return new InboundMatch(null, $submission, InboundMatchMethod::EMBEDDED_HEADERS, InboundMatchConfidence::PROBABLE);
            }
        }

        $fromDomain = $mail->from !== null && str_contains($mail->from, '@') ? strtolower(substr($mail->from, strrpos($mail->from, '@') + 1)) : null;
        if ($fromDomain !== null && $fromDomain !== $domain) {
            $since = $this->clock->now()->modify('-' . self::HEURISTIC_WINDOW_DAYS . ' days');
            $candidates = $this->submissions->findSentToDomainSince($fromDomain, $since);
            // Two lawyers filing at the same court make the guess ambiguous: then none is made.
            if (count($candidates) === 1) {
                return new InboundMatch(null, $candidates[0], InboundMatchMethod::HEURISTIC, InboundMatchConfidence::PROBABLE);
            }
        }

        return null;
    }
}
