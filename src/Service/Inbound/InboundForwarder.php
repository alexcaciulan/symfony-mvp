<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\FilingSubmission;
use App\Entity\InboundMail;
use App\Entity\LegalCase;
use App\Enum\InboundAuthVerdict;
use App\Service\AuditLogService;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Forwards a message received on the platform mailbox to the lawyer of the case,
 * and only to them: never a client, an admin or anyone else. Skipped when the
 * lawyer already got it (in To or Cc, or as the copy in CC of the filing; a Bcc
 * does not count since it cannot be seen). The text goes escaped, with the
 * original attached as message/rfc822 and the reminder that the platform address
 * is not a procedural address.
 */
final class InboundForwarder
{
    public const LOOP_HEADER = 'X-LexRecovery-Forward';
    private const EXCERPT_MAX = 4000;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly InboundRawStorage $storage,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AuditLogService $auditLogService,
        private readonly ClockInterface $clock,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
        #[Autowire('%app.base_url%')]
        private readonly string $baseUrl,
    ) {}

    /** Whether the lawyer of the case already has the message. */
    public function lawyerAlreadyHasIt(InboundMail $mail, FilingSubmission $submission): bool
    {
        $lawyer = mb_strtolower(trim((string) $submission->getLegalCase()->getUser()->getEmail()));
        $copy = mb_strtolower(trim($submission->getCcAddress()));
        $visible = array_map('trim', explode(',', (string) $mail->getToAddress()));
        $visible = [...$visible, ...$mail->getCcAddresses()];

        return in_array($lawyer, $visible, true) || ($copy !== '' && in_array($copy, $visible, true));
    }

    /** @return bool whether it was sent now */
    public function forward(InboundMail $mail, FilingSubmission $submission, ParsedInboundMail $parsed): bool
    {
        if ($mail->getForwardedAt() !== null || $this->lawyerAlreadyHasIt($mail, $submission)) {
            return false;
        }
        $case = $submission->getLegalCase();
        $lawyerEmail = trim((string) $case->getUser()->getEmail());
        $raw = $this->storage->read($mail);
        if ($lawyerEmail === '' || $raw === null) {
            return false;
        }

        $link = $this->urlGenerator->generate('case_overview', ['id' => $case->getId()]);
        $caseLabel = $case->getCourtCaseNumber() ?? $case->getCaseNumber();
        $context = [
            'caseLabel' => $caseLabel,
            'caseUrl' => rtrim($this->baseUrl, '/') . $link,
            'from' => (string) $mail->getFromAddress(),
            'subject' => (string) $mail->getSubject(),
            'receivedAt' => $mail->getReceivedAt()->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i'),
            'excerpt' => mb_substr($parsed->text, 0, self::EXCERPT_MAX),
            'authTrusted' => $mail->getAuthVerdict() === InboundAuthVerdict::TRUSTED_PASS,
        ];
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->translator->trans('email.sender_name')))
            ->to($lawyerEmail)
            ->subject($this->translator->trans('inbound.forward.subject', ['%case%' => $caseLabel, '%subject%' => mb_substr((string) $mail->getSubject(), 0, 150)]))
            ->htmlTemplate('emails/filing_forward.html.twig')
            ->textTemplate('emails/filing_forward.txt.twig')
            ->context($context);
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-forwarded');
        $email->getHeaders()->addTextHeader(self::LOOP_HEADER, (string) $mail->getId());
        $email->attach($raw, 'mesaj-original.eml', 'message/rfc822');
        $this->mailer->send($email);

        $mail->markForwarded($this->clock->now());
        $this->auditLogService->log(
            action: 'inbound_mail_forwarded',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            newData: ['submissionId' => $submission->getId(), 'inboundMailId' => $mail->getId(), 'classification' => $mail->getClassification()?->value],
            category: AuditLogService::CATEGORY_FILING_SUBMISSION,
            actor: $case->getUser(),
        );

        return true;
    }
}
