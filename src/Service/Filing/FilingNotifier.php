<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingEmailPart;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Enum\FilingPartStatus;
use App\Enum\NotificationType;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the lawyer is told about a court filing. Never "filed", "registered" or
 * "received": the platform only knows that a mail server accepted a message.
 * Failure notices carry the case label, the kind of error and the SMTP code only:
 * no debtor data, no amounts and no recipient address, since they also go by email.
 * Their dedup key names the submission, the part, the event and the attempt, so a
 * new failure is never hidden behind an earlier one.
 */
final class FilingNotifier
{
    public function __construct(
        private readonly NotificationDispatcherInterface $dispatcher,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%app.base_url%')]
        private readonly string $baseUrl,
    ) {}

    public function petitionSent(FilingSubmission $submission): void
    {
        $case = $submission->getLegalCase();
        $this->dispatch($case, NotificationType::FILING_SENT, 'filing_sent', 'info', [
            '%case%' => $this->caseLabel($case),
            '%address%' => $submission->getToAddress(),
        ], 'filing_sent:' . $submission->getId());
    }

    /**
     * @param list<string> $sent    titles of the documents that left
     * @param list<string> $notSent titles of the documents that did not
     */
    public function annexesCancelled(FilingSubmission $submission, array $sent, array $notSent): void
    {
        $case = $submission->getLegalCase();
        $this->dispatch($case, NotificationType::FILING_ANNEXES_CANCELLED, 'filing_annexes_cancelled', 'warning', [
            '%case%' => $this->caseLabel($case),
            '%sent%' => implode(', ', $sent),
            '%not_sent%' => implode(', ', $notSent),
        ], 'filing_annexes_cancelled:' . $submission->getId());
    }

    public const VARIANT_RETRYING = 'retrying';
    public const VARIANT_EXHAUSTED = 'exhausted';

    public function failedTemporary(FilingSubmission $submission, FilingEmailPart $part, string $variant, ?\DateTimeImmutable $nextRetryAt = null): void
    {
        $case = $submission->getLegalCase();
        $this->dispatch($case, NotificationType::FILING_FAILED_TEMPORARY, 'filing_failed_temporary.' . $variant, $variant === self::VARIANT_RETRYING ? 'warning' : 'error', [
            '%case%' => $this->caseLabel($case),
            '%k%' => (string) $part->getSequence(),
            '%n%' => (string) $part->getTotal(),
            '%code%' => $this->code($part),
            '%next%' => $nextRetryAt?->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i') ?? '',
        ], $this->key($submission, $part, 'temporary_' . $variant), 'email.filing_failed_temporary.subject');
    }

    public function failedPermanent(FilingSubmission $submission, FilingEmailPart $part, bool $addressInvalidated): void
    {
        $case = $submission->getLegalCase();
        $this->dispatch($case, NotificationType::FILING_FAILED_PERMANENT, $addressInvalidated ? 'filing_failed_permanent.address' : 'filing_failed_permanent.other', 'error', [
            '%case%' => $this->caseLabel($case),
            '%k%' => (string) $part->getSequence(),
            '%n%' => (string) $part->getTotal(),
            '%code%' => $this->code($part),
        ], $this->key($submission, $part, 'permanent'), 'email.filing_failed_permanent.subject');
    }

    public function failedSize(FilingSubmission $submission, FilingEmailPart $part, bool $resplitPossible): void
    {
        $case = $submission->getLegalCase();
        $this->dispatch($case, NotificationType::FILING_FAILED_SIZE, $resplitPossible ? 'filing_failed_size.resplit' : 'filing_failed_size.other_channel', 'error', [
            '%case%' => $this->caseLabel($case),
            '%k%' => (string) $part->getSequence(),
            '%n%' => (string) $part->getTotal(),
            '%code%' => $this->code($part),
        ], $this->key($submission, $part, 'size'), 'email.filing_failed_size.subject');
    }

    public function failedInternal(FilingSubmission $submission, FilingEmailPart $part): void
    {
        $case = $submission->getLegalCase();
        $this->dispatch($case, NotificationType::FILING_FAILED_INTERNAL, 'filing_failed_internal', 'error', [
            '%case%' => $this->caseLabel($case),
            '%k%' => (string) $part->getSequence(),
            '%n%' => (string) $part->getTotal(),
        ], $this->key($submission, $part, 'internal'), 'email.filing_failed_internal.subject');
    }

    public function needsReview(FilingSubmission $submission, string $reasonKey): void
    {
        $case = $submission->getLegalCase();
        $attempts = 0;
        foreach ($submission->getParts() as $part) {
            $attempts += $part->getAttempts()->count();
        }
        $short = substr((string) strrchr('.' . $reasonKey, '.'), 1);
        $this->dispatch($case, NotificationType::FILING_NEEDS_REVIEW, 'filing_needs_review', 'warning', [
            '%case%' => $this->caseLabel($case),
            '%reason%' => $this->translator->trans($reasonKey),
        ], sprintf('filing:%d:needs_review:%s:a%d', (int) $submission->getId(), $short, $attempts), 'email.filing_needs_review.subject');
    }

    /** The petition left and an annex email failed with nothing left on its way. */
    public function partiallySent(FilingSubmission $submission, FilingEmailPart $failed): void
    {
        $case = $submission->getLegalCase();
        $this->dispatch($case, NotificationType::FILING_PARTIALLY_SENT, 'filing_partially_sent', 'error', [
            '%case%' => $this->caseLabel($case),
            '%k%' => (string) $failed->getSequence(),
            '%n%' => (string) $failed->getTotal(),
            '%kind%' => $this->translator->trans('filing.failure.kind.' . self::kindOf($failed)),
            '%code%' => $this->code($failed),
        ], $this->key($submission, $failed, 'partially_sent'), 'email.filing_partially_sent.subject');
    }

    /** The failure kind of a failed part, as used in the filing.failure.kind.* keys. */
    public static function kindOf(FilingEmailPart $part): string
    {
        return match ($part->getStatus()) {
            FilingPartStatus::FAILED_TEMPORARY_EXHAUSTED, FilingPartStatus::RETRY_SCHEDULED => 'temporary',
            FilingPartStatus::FAILED_PERMANENT => 'permanent',
            FilingPartStatus::FAILED_SIZE => 'size',
            FilingPartStatus::UNCERTAIN => 'uncertain',
            default => 'internal',
        };
    }

    private function key(FilingSubmission $submission, FilingEmailPart $part, string $event): string
    {
        return sprintf('filing:%d:p%d:%s:a%d', (int) $submission->getId(), $part->getSequence(), $event, $part->latestAttempt()?->getAttemptNo() ?? 0);
    }

    private function code(FilingEmailPart $part): string
    {
        return trim(($part->getLastSmtpCode() ?? '-') . ' ' . ($part->getLastEnhancedStatus() ?? ''));
    }

    /** @param array<string, string> $params */
    private function dispatch(LegalCase $case, NotificationType $type, string $key, string $variant, array $params, string $dedupKey, ?string $subjectKey = null): void
    {
        $link = $this->urlGenerator->generate('case_overview', ['id' => $case->getId()]);
        $title = $this->translator->trans("notification.$key.title", $params);
        $message = $this->translator->trans("notification.$key.message", $params);

        $this->dispatcher->dispatch(new NotificationDispatch(
            user: $case->getUser(),
            legalCase: $case,
            type: $type,
            title: $title,
            message: $message,
            resourceLink: $link,
            variant: $variant,
            emailSubject: $this->translator->trans($subjectKey ?? "email.$key.subject", $params),
            emailTemplate: 'emails/case_status.html.twig',
            emailContext: [
                'case' => $case,
                'caseLabel' => $params['%case%'],
                'caseUrl' => rtrim($this->baseUrl, '/') . $link,
                'heading' => $title,
                'body' => $message,
            ],
            dedupKey: $dedupKey,
        ));
    }

    private function caseLabel(LegalCase $case): string
    {
        return $case->getCourtCaseNumber() ?? $case->getCaseNumber();
    }
}
