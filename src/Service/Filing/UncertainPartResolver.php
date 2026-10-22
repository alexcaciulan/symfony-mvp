<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingAttempt;
use App\Entity\FilingEmailPart;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingAttemptOutcome;
use App\Enum\FilingAttemptSource;
use App\Enum\FilingPartStatus;
use App\Service\AuditLogService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ends the doubt about an email whose SMTP hand-off was interrupted. The lawyer
 * declares whether the copy sent to them in CC arrived: that copy shows the
 * message left, not that the court received it, and the declaration is the
 * lawyer's own. An admin may only unblock a case operationally, with a reason:
 * never by declaring the petition accepted, and never by touching the filing date.
 */
final class UncertainPartResolver
{
    public const CONFIRM_RECEIVED = 'cc_received';
    public const CONFIRM_NOT_RECEIVED = 'cc_not_received';
    public const OVERRIDE_ACCEPTED = 'accepted';
    public const OVERRIDE_FAILED = 'failed';
    private const MIN_REASON_LENGTH = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilingPartOutcomeApplier $outcomes,
        private readonly FilingLockQuery $filingLock,
        private readonly AuditLogService $auditLogService,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * For the petition the received declaration also states its consequence:
     * the petition counts as sent, at an approximated time.
     *
     * @return array<string, string> key => text as shown
     */
    public function confirmationTexts(string $kind, FilingEmailPart $part): array
    {
        return [$kind => $this->translator->trans('filing.uncertain.confirm.' . $kind . ($kind === self::CONFIRM_RECEIVED && $part->isFirst() ? '_petition' : ''))];
    }

    /**
     * @param array<string, bool> $confirmed
     *
     * @throws FilingSendBlockedException
     */
    public function declareReceived(FilingEmailPart $part, User $lawyer, array $confirmed): void
    {
        $this->resolveAsLawyer($part, $lawyer, $confirmed, self::CONFIRM_RECEIVED, function (FilingEmailPart $part, User $lawyer): void {
            // The server's acceptance time is unknown; the start of the last SMTP try stands in for it.
            $at = $part->latestSmtpAttempt()?->getStartedAt() ?? $this->clock->now();
            $this->addAttempt($part, FilingAttemptSource::LAWYER_CC_CHECK, FilingAttemptOutcome::ACCEPTED, 'Lawyer declared the CC copy received; sentAt approximated by the last SMTP try');
            $this->outcomes->accepted($part, $at, FilingAttemptSource::LAWYER_CC_CHECK, $lawyer);
        });
    }

    /**
     * @param array<string, bool> $confirmed
     *
     * @throws FilingSendBlockedException
     */
    public function declareNotReceived(FilingEmailPart $part, User $lawyer, array $confirmed): void
    {
        $this->resolveAsLawyer($part, $lawyer, $confirmed, self::CONFIRM_NOT_RECEIVED, function (FilingEmailPart $part, User $lawyer): void {
            $this->addAttempt($part, FilingAttemptSource::LAWYER_CC_CHECK, FilingAttemptOutcome::INTERNAL, 'Lawyer declared the CC copy not received');
            $this->outcomes->failed($part, FilingPartStatus::FAILED_INTERNAL, null, 'filing.uncertain.not_received', $lawyer);
        });
    }

    /**
     * @param string $outcome self::OVERRIDE_ACCEPTED (annexes only) or self::OVERRIDE_FAILED
     *
     * @throws FilingSendBlockedException
     */
    public function adminOverride(FilingEmailPart $part, User $admin, string $outcome, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new FilingSendBlockedException('filing.override.error.reason_required');
        }
        if (!in_array($outcome, [self::OVERRIDE_ACCEPTED, self::OVERRIDE_FAILED], true)) {
            throw new FilingSendBlockedException('filing.override.error.not_allowed');
        }
        // Whether the petition reached the court is the lawyer's declaration, never the admin's.
        if ($outcome === self::OVERRIDE_ACCEPTED && $part->isFirst()) {
            throw new FilingSendBlockedException('filing.override.error.petition_lawyer_only');
        }

        $this->inLock($part, function (FilingEmailPart $part) use ($admin, $outcome, $reason): void {
            $old = ['part' => $part->getStatus()->value, 'submission' => $part->getSubmission()->getDeliveryStatus()->value];
            $message = mb_substr('Admin override: ' . $reason, 0, 500);
            if ($outcome === self::OVERRIDE_ACCEPTED) {
                $this->addAttempt($part, FilingAttemptSource::ADMIN_OVERRIDE, FilingAttemptOutcome::ACCEPTED, $message);
                $this->outcomes->accepted($part, $part->latestSmtpAttempt()?->getStartedAt() ?? $this->clock->now(), FilingAttemptSource::ADMIN_OVERRIDE, $admin);
            } else {
                $this->addAttempt($part, FilingAttemptSource::ADMIN_OVERRIDE, FilingAttemptOutcome::INTERNAL, $message);
                $this->outcomes->failed($part, FilingPartStatus::FAILED_INTERNAL, null, 'filing.override.failed', $admin);
            }
            $this->audit($part, 'filing_part_admin_override', $admin, [
                'sequence' => $part->getSequence(),
                'outcome' => $outcome,
                'reason' => $reason,
                'old' => $old,
                'new' => ['part' => $part->getStatus()->value, 'submission' => $part->getSubmission()->getDeliveryStatus()->value],
            ]);
        });
    }

    /**
     * @param array<string, bool>                     $confirmed
     * @param callable(FilingEmailPart, User): void   $apply
     */
    private function resolveAsLawyer(FilingEmailPart $part, User $lawyer, array $confirmed, string $kind, callable $apply): void
    {
        if ($part->getSubmission()->getLegalCase()->getUser() !== $lawyer) {
            throw new FilingSendBlockedException('filing.send.blocker.not_owner');
        }
        $texts = $this->confirmationTexts($kind, $part);
        if (($confirmed[$kind] ?? false) !== true) {
            throw new FilingSendBlockedException('filing.send.error.confirmation_required');
        }

        $this->inLock($part, function (FilingEmailPart $part) use ($lawyer, $texts, $kind, $apply): void {
            $part->getSubmission()->appendConfirmation('uncertain_p' . $part->getSequence(), $texts, $lawyer, $this->clock->now());
            $apply($part, $lawyer);
            $this->audit($part, 'filing_part_uncertain_resolved', $lawyer, [
                'sequence' => $part->getSequence(),
                'declaration' => $kind,
                'text' => $texts[$kind],
                ...($kind === self::CONFIRM_RECEIVED && $part->isFirst() ? ['sentAtApproximated' => true, 'sentAt' => $part->getSentAt()?->format(\DateTimeInterface::ATOM)] : []),
            ]);
        });
    }

    /** @param callable(FilingEmailPart): void $work */
    private function inLock(FilingEmailPart $part, callable $work): void
    {
        $submission = $part->getSubmission();
        $case = $submission->getLegalCase();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->lock($case, LockMode::PESSIMISTIC_WRITE);
            $this->em->lock($part, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($part);
            $this->em->refresh($submission);
            if ($part->getStatus() !== FilingPartStatus::UNCERTAIN) {
                throw new FilingSendBlockedException('filing.uncertain.refused');
            }
            $work($part);
            $this->em->flush();
            $connection->commit();
            $this->filingLock->forget($case);
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    private function addAttempt(FilingEmailPart $part, FilingAttemptSource $source, FilingAttemptOutcome $outcome, string $message): void
    {
        $now = $this->clock->now();
        $attempt = new FilingAttempt($part, $part->nextAttemptNo(), $source, $now);
        $attempt->finish($outcome, $now, message: $message);
        $this->em->persist($attempt);
    }

    /** @param array<string, mixed> $data */
    private function audit(FilingEmailPart $part, string $action, User $actor, array $data): void
    {
        $this->auditLogService->log(
            action: $action,
            entityType: LegalCase::class,
            entityId: (string) $part->getSubmission()->getLegalCase()->getId(),
            newData: ['submissionId' => $part->getSubmission()->getId(), ...$data],
            category: AuditLogService::CATEGORY_FILING_SUBMISSION,
            actor: $actor,
        );
    }
}
