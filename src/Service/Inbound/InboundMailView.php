<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\FilingSubmission;
use App\Entity\InboundMail;
use App\Enum\InboundMatchMethod;
use App\Repository\InboundMailRepository;

/**
 * What the case card shows of the messages tied to a submission: metadata and a
 * short plain-text excerpt (the template escapes it; HTML is never rendered). A
 * message tied only by the sender-domain guess is never shown to the lawyer: it
 * may belong to another client.
 */
final class InboundMailView
{
    private const EXCERPT_MAX = 280;

    public function __construct(
        private readonly InboundMailRepository $mails,
        private readonly InboundRawStorage $storage,
        private readonly InboundMailParser $parser,
    ) {}

    public static function isVisibleToLawyer(InboundMail $mail): bool
    {
        return $mail->getMatchedSubmission() !== null
            && $mail->getMatchMethod() !== null
            && $mail->getMatchMethod() !== InboundMatchMethod::HEURISTIC;
    }

    /** @return list<array{mail: InboundMail, excerpt: ?string}> */
    public function rows(FilingSubmission $submission): array
    {
        $rows = [];
        foreach ($this->mails->findForSubmission($submission) as $mail) {
            if (!self::isVisibleToLawyer($mail)) {
                continue;
            }
            $rows[] = ['mail' => $mail, 'excerpt' => $this->excerpt($mail)];
        }

        return $rows;
    }

    private function excerpt(InboundMail $mail): ?string
    {
        if ($mail->getRawMime() !== InboundMail::MIME_EML || $mail->getClassification()?->isBounce()) {
            return null;
        }
        $raw = $this->storage->read($mail);
        if ($raw === null) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $this->parser->parse($raw)->text));

        return $text === '' ? null : (mb_strlen($text) > self::EXCERPT_MAX ? mb_substr($text, 0, self::EXCERPT_MAX) . '…' : $text);
    }
}
