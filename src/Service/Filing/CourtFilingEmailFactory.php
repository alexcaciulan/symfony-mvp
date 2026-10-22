<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\Document;
use App\Entity\FilingEmailPart;
use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\FilingPartRole;
use App\Enum\OpisPlacement;
use App\Enum\StampDutyStatus;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The email that carries a filing to the court: a covering letter, not the
 * procedural act. It names the creditor, the lawyer and the attached files,
 * asks for a receipt and the case number, and says the platform only transmits.
 * It never states the amount, the legal grounds, a registration, or a payment
 * the package does not prove. Rendered in Romanian whatever the lawyer's locale:
 * it is addressed to a Romanian court.
 */
final class CourtFilingEmailFactory
{
    public const TEMPLATE_VERSION = 'court_email.v1';
    private const LOCALE = 'ro';
    private const SUBJECT_MAX_LENGTH = 240;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly DebtorSubjectNameFormatter $debtorNames,
        private readonly FilingAnnexSelector $annexSelector,
        private readonly string $uploadsDir,
        private readonly string $courtFilingFrom,
        private readonly string $courtFilingBounceDomain,
    ) {}

    public function fromAddress(): string
    {
        return mb_strtolower(trim($this->courtFilingFrom));
    }

    public function messageId(string $token, int $sequence): string
    {
        return sprintf('<filing.%s.%d@%s>', $token, $sequence, $this->courtFilingBounceDomain);
    }

    public function envelopeSender(string $verpToken): string
    {
        return sprintf('bounces+%s@%s', $verpToken, $this->courtFilingBounceDomain);
    }

    public function fromName(User $lawyer): string
    {
        return $this->trans('court_email.from_name', ['%name%' => $this->lawyerName($lawyer)]);
    }

    /**
     * Capped so it fits filing_email_part.subject (VARCHAR 255) whatever the party
     * names; only the names are shortened, the part counter always stays visible.
     */
    public function subject(LegalCase $case, int $sequence, int $total): string
    {
        $subject = $this->trans('court_email.subject', [
            '%creditor%' => trim((string) $case->getCreditor()?->getName()),
            '%debtor%' => $this->debtorLabel($case),
        ]);
        if ($total <= 1) {
            return mb_strimwidth($subject, 0, self::SUBJECT_MAX_LENGTH, '…');
        }
        $partParams = ['%k%' => $sequence, '%n%' => $total];
        $overhead = mb_strlen($this->trans('court_email.subject_part', ['%subject%' => ''] + $partParams));

        return $this->trans('court_email.subject_part', [
            '%subject%' => mb_strimwidth($subject, 0, self::SUBJECT_MAX_LENGTH - $overhead, '…'),
        ] + $partParams);
    }

    /**
     * The package files grouped by the email they travel in, as the size plan
     * laid them out.
     *
     * @return list<list<FilingPackagePart>>
     */
    public function emailsOf(FilingPackage $package): array
    {
        $byToken = [];
        foreach ($package->getParts() as $part) {
            $byToken[$part->getPartToken()] = $part;
        }

        $emails = [];
        foreach ($package->getSizePlan()['emails'] ?? [] as $email) {
            $parts = [];
            foreach ($email['parts'] ?? [] as $token) {
                if (isset($byToken[$token])) {
                    $parts[] = $byToken[$token];
                }
            }
            usort($parts, static fn (FilingPackagePart $a, FilingPackagePart $b): int => $a->getPosition() <=> $b->getPosition());
            $emails[] = $parts;
        }

        return $emails;
    }

    /** Stable ASCII name, numbered over the whole package so the court sees one sequence. */
    public function attachmentName(FilingPackage $package, FilingPackagePart $part): string
    {
        $index = 1;
        foreach ($package->getParts() as $each) {
            if ($each === $part) {
                break;
            }
            ++$index;
        }

        $stem = match ($part->getRole()) {
            FilingPartRole::PETITION => $package->getOpisPlacement() === OpisPlacement::MERGED ? 'cerere-si-opis' : 'cerere',
            FilingPartRole::ANNEX_BUNDLE => ($part->getVolumeCount() ?? 1) > 1 ? 'anexe-vol' . $part->getVolume() : 'anexe',
            default => $part->getRole()->fileStem(),
        };

        return sprintf('%02d-%s.pdf', $index, $stem);
    }

    /**
     * The plain-text body of one email of the package.
     *
     * @throws CourtFilingEmailException when the lawyer's block cannot be written as decided
     */
    public function body(LegalCase $case, FilingPackage $package, int $sequence): string
    {
        $lawyer = $case->getUser();
        $emails = $this->emailsOf($package);
        $total = count($emails);
        $creditor = $case->getCreditor();
        $lawyerName = $this->lawyerName($lawyer);

        $lines = [$this->trans('court_email.salutation', ['%court%' => (string) $case->getCourt()?->getName()]), ''];

        if ($sequence === 1) {
            $cui = trim((string) $creditor?->getCui());
            $lines[] = $this->trans($cui !== '' ? 'court_email.creditor_line' : 'court_email.creditor_line_no_cui', [
                '%creditor%' => trim((string) $creditor?->getName()),
                '%seat%' => trim((string) $creditor?->getAddress()),
                '%cui%' => $cui,
                '%lawyer%' => $lawyerName,
            ]);
        } else {
            $lines[] = $this->trans('court_email.continuation_line', [
                '%creditor%' => trim((string) $creditor?->getName()),
                '%lawyer%' => $lawyerName,
                '%k%' => $sequence,
                '%n%' => $total,
            ]);
        }

        $lines[] = '';
        $lines[] = $this->trans('court_email.documents_intro');
        foreach ($emails[$sequence - 1] ?? [] as $part) {
            $lines[] = $this->documentLine($package, $part);
        }

        if ($sequence === 1 && $total > 1) {
            $lines[] = '';
            $lines[] = $this->trans('court_email.package_intro', ['%n%' => $total]);
            foreach ($emails as $i => $parts) {
                $lines[] = $this->trans('court_email.package_email_line', [
                    '%k%' => $i + 1,
                    '%files%' => implode(', ', array_map(fn (FilingPackagePart $p): string => $this->attachmentName($package, $p), $parts)),
                ]);
            }
            $lines[] = $this->trans('court_email.annexes_follow');
        }

        if ($sequence === 1) {
            $lines[] = '';
            $lines[] = $this->trans($this->stampDutyKey($case, $package));
        }

        $lines[] = '';
        $lines[] = $this->trans('court_email.receipt_request', ['%lawyer_email%' => (string) $lawyer->getEmail()]);

        $lines[] = '';
        $lines[] = $this->trans('court_email.signature_closing');
        $lines[] = $this->trans('court_email.signature_name', ['%name%' => $lawyerName]);
        $lines[] = $this->powerOfAttorneyLine($case);
        $phone = trim((string) $lawyer->getPhone());
        if ($phone !== '') {
            $lines[] = $this->trans('court_email.signature_phone', ['%phone%' => $phone]);
        }
        $lines[] = $this->trans('court_email.signature_email', ['%email%' => (string) $lawyer->getEmail()]);

        $lines[] = '';
        $lines[] = '--';
        $lines[] = $this->trans('court_email.footer_platform');
        $lines[] = $this->trans('court_email.footer_signed');

        return implode("\n", $lines) . "\n";
    }

    /**
     * Serializes one part once. The attachments are the signed files, checked
     * against the hash recorded at their upload.
     *
     * @throws CourtFilingEmailException
     */
    public function build(FilingEmailPart $part, string $effectiveTo, \DateTimeImmutable $now): BuiltFilingEmail
    {
        $submission = $part->getSubmission();
        $case = $submission->getLegalCase();
        $package = $submission->getPackage();
        $lawyer = $case->getUser();
        $lawyerEmail = trim((string) $lawyer->getEmail());
        if ($lawyerEmail === '') {
            throw new CourtFilingEmailException('filing.send.blocker.lawyer_email_missing');
        }

        $email = (new Email())
            ->from(new Address($this->fromAddress(), $this->fromName($lawyer)))
            ->to(new Address($effectiveTo))
            ->cc(new Address($lawyerEmail))
            ->replyTo(new Address($lawyerEmail))
            ->subject($part->getSubject())
            ->text($this->body($case, $package, $part->getSequence()), 'utf-8');

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', self::bare($part->getMessageId()));
        $headers->addDateHeader('Date', $now);
        if ($part->getInReplyTo() !== null) {
            $headers->addIdHeader('In-Reply-To', self::bare($part->getInReplyTo()));
            $headers->addIdHeader('References', self::bare((string) ($part->getReferences() ?? $part->getInReplyTo())));
        }
        $headers->addTextHeader('X-LexRecovery-Ref', $submission->getToken());
        $headers->addMailboxListHeader('Disposition-Notification-To', [new Address($this->fromAddress())]);

        foreach ($part->getOrderedPackageParts() as $packagePart) {
            $email->attach($this->signedBytes($packagePart), $this->attachmentName($package, $packagePart), 'application/pdf');
        }

        $bytes = $email->toString();

        return new BuiltFilingEmail(
            $bytes,
            hash('sha256', $bytes),
            strlen($bytes),
            $part->getMessageId(),
            new Envelope(new Address($this->envelopeSender($part->getVerpToken())), [new Address($effectiveTo), new Address($lawyerEmail)]),
        );
    }

    /**
     * The power of attorney the lawyer acts under, or null when its series,
     * number or date is missing (the block is then never written with blanks).
     *
     * @return array{series: string, number: string, date: \DateTimeImmutable}|null
     */
    public function powerOfAttorneyDetails(LegalCase $case): ?array
    {
        $document = $this->annexSelector->chosenPowerOfAttorney($case);
        if (!$document instanceof Document) {
            return null;
        }
        $series = trim((string) $document->getPowerOfAttorneySeries());
        $number = trim((string) $document->getPowerOfAttorneyNumber());
        $date = $document->getPowerOfAttorneyDate();
        if ($series === '' || $number === '' || $date === null) {
            return null;
        }

        return ['series' => $series, 'number' => $number, 'date' => $date];
    }

    private function powerOfAttorneyLine(LegalCase $case): string
    {
        $details = $this->powerOfAttorneyDetails($case) ?? throw new CourtFilingEmailException('filing.send.blocker.power_of_attorney_details');

        return $this->trans('court_email.signature_poa', [
            '%series%' => $details['series'],
            '%number%' => $details['number'],
            '%date%' => $details['date']->format('d.m.Y'),
        ]);
    }

    /** Same predicates as the petition and the package, so the email never contradicts them. */
    private function stampDutyKey(LegalCase $case, FilingPackage $package): string
    {
        if ($package->includesStampDutyProof()) {
            return 'court_email.stamp_duty_proof';
        }
        if ($case->getStampDutyStatus() === StampDutyStatus::ACHITATA) {
            return 'court_email.stamp_duty_registry';
        }

        return 'court_email.stamp_duty_later';
    }

    private function documentLine(FilingPackage $package, FilingPackagePart $part): string
    {
        return $this->trans('court_email.document_line', [
            '%file%' => $this->attachmentName($package, $part),
            '%title%' => $part->getTitle(),
        ]);
    }

    private function debtorLabel(LegalCase $case): string
    {
        $debtors = $case->getDebtors()->toArray();
        usort($debtors, static fn (LegalCaseDebtor $a, LegalCaseDebtor $b): int => $a->getPosition() <=> $b->getPosition());

        return $this->debtorNames->formatDebtors(
            array_map(static fn (LegalCaseDebtor $d): array => ['type' => $d->getPersonType(), 'name' => $d->getName(), 'cui' => $d->getCui()], $debtors),
            $this->trans('court_email.debtor_others_suffix'),
        );
    }

    private function signedBytes(FilingPackagePart $part): string
    {
        $path = $part->getSignedPath();
        $bytes = $path !== null ? @file_get_contents($this->uploadsDir . '/' . $path) : false;
        if ($bytes === false || hash('sha256', $bytes) !== $part->getSignedSha256()) {
            throw new CourtFilingEmailException('filing.status.review.signed_file_changed');
        }

        return $bytes;
    }

    private function lawyerName(User $lawyer): string
    {
        return trim((string) $lawyer->getFullName());
    }

    private static function bare(string $messageId): string
    {
        return trim($messageId, '<> ');
    }

    /** @param array<string, string|int> $params */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'messages', self::LOCALE);
    }
}
