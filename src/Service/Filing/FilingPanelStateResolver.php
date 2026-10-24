<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingPackage;
use App\Entity\FilingSubmission;
use App\Enum\CaseStatus;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingEvidenceStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartStatus;

/**
 * Picks the one state the filing tab shows when several conditions hold at once.
 * The transmission comes first, in the order the lawyer has to deal with it (a
 * late bounce after filing, an undelivered petition, an unknown outcome, a
 * stalled send, retries), then the package. Never says that the court received
 * or registered anything: an accepted email is "sent", a receipt is an automatic
 * message, and the filing date stays the lawyer's declaration.
 */
final class FilingPanelStateResolver
{
    public const ACTION_BUILD = 'build';
    public const ACTION_REBUILD = 'rebuild';
    public const ACTION_UNSIGNED_ZIP = 'unsigned_zip';
    public const ACTION_CONFIRM_PART = 'confirm_part';
    public const ACTION_UPLOAD_PARTS = 'upload_parts';
    public const ACTION_VERIFY_SEND = 'verify_send';
    public const ACTION_OTHER_CHANNEL_ARCHIVE = 'other_channel_archive';
    public const ACTION_DECLARE_OTHER_CHANNEL = 'declare_other_channel';
    public const ACTION_RESUME = 'resume';
    public const ACTION_CLOSE_REMAINING = 'close_remaining';
    public const ACTION_RESPLIT = 'resplit';
    public const ACTION_CANCEL = 'cancel';
    public const ACTION_SAVE_FILED_DATE = 'save_filed_date';
    public const ACTION_UPLOAD_RECEIPT = 'upload_receipt';
    public const ACTION_PORTAL_CHECK = 'portal_check';
    public const ACTION_COURT_ADDRESSES = 'court_addresses';
    public const ACTION_EML_DOWNLOAD = 'eml_download';
    public const ACTION_LATE_ACK = 'late_ack';
    public const ACTION_CC_DECLARE = 'cc_declare';
    public const ACTION_RESOLVE_BLOCKER = 'resolve_blocker';

    /** Files of the case leave the platform: offered to the lawyer of the case only. */
    private const OWNER_ONLY_ACTIONS = [
        self::ACTION_UNSIGNED_ZIP,
        self::ACTION_OTHER_CHANNEL_ARCHIVE,
        self::ACTION_EML_DOWNLOAD,
        self::ACTION_CONFIRM_PART,
        self::ACTION_UPLOAD_PARTS,
        self::ACTION_VERIFY_SEND,
        self::ACTION_RESUME,
        self::ACTION_CLOSE_REMAINING,
        self::ACTION_RESPLIT,
        self::ACTION_CANCEL,
        self::ACTION_SAVE_FILED_DATE,
        self::ACTION_UPLOAD_RECEIPT,
        self::ACTION_LATE_ACK,
        self::ACTION_CC_DECLARE,
    ];

    private const SIGNING_NOTES = [
        'filing.signature.notice.validity',
        'filing.signature.notice.validator_consent',
        'filing.signature.notice.responsibility',
        'filing.package.unsigned_instructions',
        'filing.package.cover_text_provisional',
        'filing.package.compression_note',
    ];

    private const RESPONSIBILITY = 'filing.failure.responsibility';
    private const NEAR_DEADLINE = 'filing.panel.legal.near_deadline';

    public function resolve(FilingPanelInput $in): FilingPanelState
    {
        $state = $this->submissionDominates($in) ? $this->submissionState($in) : $this->packageState($in);

        return $this->finish($in, $state);
    }

    /**
     * A transmission stays the subject of the tab while it holds the case, once its
     * petition left, or until the package it carried is replaced.
     */
    private function submissionDominates(FilingPanelInput $in): bool
    {
        $submission = $in->submission;
        if ($submission === null) {
            return false;
        }
        if ($submission->isActive() || $in->lateReview) {
            return true;
        }
        if (in_array($submission->getDeliveryStatus(), [FilingDeliveryStatus::SENT, FilingDeliveryStatus::CLOSED_PARTIAL, FilingDeliveryStatus::UNDELIVERED], true)) {
            return true;
        }

        return $in->package === null
            || $in->package->getId() === $submission->getPackage()->getId()
            || $in->resplitDone;
    }

    /** @return array<string, mixed> */
    private function submissionState(FilingPanelInput $in): array
    {
        $submission = $in->submission;
        assert($submission instanceof FilingSubmission);
        $status = $submission->getDeliveryStatus();
        $petitionAccepted = $submission->isPetitionAccepted();
        $failurePart = $in->failure['part'] ?? null;
        $failureError = $failurePart?->getLastError();
        $total = $submission->getParts()->count();

        if ($in->lateReview) {
            return $this->state('late_review', FilingPanelState::TONE_AMBER, self::ACTION_LATE_ACK, [self::ACTION_COURT_ADDRESSES], ['filing.receipt.disclaimer', self::RESPONSIBILITY], titleKey: 'filing.undelivered.late_headline', explanationKey: 'filing.undelivered.late_explain', sending: FilingPanelState::STEP_PROBLEM, sendingSummary: 'returned');
        }

        if ($status === FilingDeliveryStatus::UNDELIVERED) {
            $petition = $submission->getPart(1);

            return $this->state(
                'undelivered',
                FilingPanelState::TONE_RED,
                self::ACTION_OTHER_CHANNEL_ARCHIVE,
                [$in->verdict?->isAllowed() ? self::ACTION_VERIFY_SEND : self::ACTION_COURT_ADDRESSES, self::ACTION_DECLARE_OTHER_CHANNEL],
                ['filing.undelivered.deadline_warning', self::RESPONSIBILITY],
                titleKey: SmtpFailureClassifier::isUnknownMailboxStatus($petition?->getLastEnhancedStatus())
                    ? 'filing.panel.title.undelivered_unknown_address'
                    : 'filing.panel.title.undelivered',
                critical: 'filing.panel.legal.deadline_short',
                sending: FilingPanelState::STEP_PROBLEM,
                sendingSummary: 'returned',
                legalOpen: true,
            );
        }

        if ($in->uncertainPart !== null) {
            return $this->state('uncertain', FilingPanelState::TONE_AMBER, self::ACTION_CC_DECLARE, [], ['filing.uncertain.explain', self::RESPONSIBILITY], titleParams: ['%k%' => $in->uncertainPart->getSequence(), '%n%' => $total], explanationParams: ['%cc%' => $submission->getCcAddress()], sending: FilingPanelState::STEP_PROBLEM, sendingSummary: 'unknown');
        }

        if ($failurePart !== null && $failureError === UncertainPartResolver::OVERRIDE_FAILED_ERROR) {
            return $this->afterFailure($in, 'admin_failed', ['%k%' => $failurePart->getSequence(), '%n%' => $total]);
        }

        if ($failurePart !== null && $failureError === UncertainPartResolver::NOT_RECEIVED_ERROR) {
            return $this->afterFailure($in, $failurePart->isFirst() ? 'cc_not_received_petition' : 'cc_not_received_annex', ['%k%' => $failurePart->getSequence(), '%n%' => $total]);
        }

        // A refused email stops the sequence: the emails after it stay pending without
        // anything queued for them, so the lawyer has to resume or close either way.
        $stopped = $in->stalled || ($submission->hasFailedPart() && ($in->resumable || $in->closable));
        if ($stopped && $petitionAccepted) {
            return $this->state('stalled', FilingPanelState::TONE_AMBER, $in->resumable ? self::ACTION_RESUME : null, $in->closable ? [self::ACTION_CLOSE_REMAINING] : [], [self::RESPONSIBILITY], critical: 'filing.close.warning', sending: FilingPanelState::STEP_PROBLEM, sendingSummary: 'annex_failed');
        }

        if ($status === FilingDeliveryStatus::NEEDS_REVIEW) {
            $secondary = array_values(array_filter([
                $in->resumable && $in->closable ? self::ACTION_CLOSE_REMAINING : null,
                $in->cancellable ? self::ACTION_CANCEL : null,
            ]));
            $primary = $in->resumable ? self::ACTION_RESUME : ($in->closable ? self::ACTION_CLOSE_REMAINING : null);

            return $this->state(
                $petitionAccepted ? 'review_after_petition' : 'review',
                FilingPanelState::TONE_AMBER,
                $primary,
                $secondary,
                [self::RESPONSIBILITY],
                critical: $petitionAccepted
                    ? ($submission->countAcceptedParts() < $total ? 'filing.close.warning' : null)
                    // Nothing reached the court server: the deadline risk must stay visible.
                    : self::NEAR_DEADLINE,
                sending: FilingPanelState::STEP_PROBLEM,
                sendingSummary: $petitionAccepted ? 'annex_failed' : 'stopped',
            );
        }

        if (in_array($status, [FilingDeliveryStatus::SENT, FilingDeliveryStatus::PARTIALLY_SENT, FilingDeliveryStatus::CLOSED_PARTIAL], true)
            && $submission->getPart(1)?->isFailed()) {
            return $this->state('petition_returned_closed', FilingPanelState::TONE_NEUTRAL, null, [self::ACTION_COURT_ADDRESSES], ['filing.receipt.disclaimer', self::RESPONSIBILITY], sending: FilingPanelState::STEP_PROBLEM, sendingSummary: 'returned');
        }

        if ($status === FilingDeliveryStatus::CLOSED_PARTIAL) {
            if ($in->filedAt !== null) {
                return $this->state('filed', FilingPanelState::TONE_GREEN, null, [self::ACTION_EML_DOWNLOAD], ['filing.receipt.disclaimer', self::RESPONSIBILITY], titleParams: ['%date%' => $in->filedAt->format('d.m.Y')], critical: 'filing.close.warning');
            }

            return $this->state('closed_partial', FilingPanelState::TONE_AMBER, $in->canSetFiledDate ? self::ACTION_SAVE_FILED_DATE : null, [self::ACTION_OTHER_CHANNEL_ARCHIVE], ['filing.receipt.disclaimer', self::RESPONSIBILITY], critical: 'filing.close.warning');
        }

        if ($failurePart !== null && $failurePart->getStatus() === FilingPartStatus::RETRY_SCHEDULED) {
            return $this->state('retrying', FilingPanelState::TONE_AMBER, null, [self::ACTION_OTHER_CHANNEL_ARCHIVE], ['filing.failure.deadline_warning', self::RESPONSIBILITY], titleParams: ['%k%' => $failurePart->getSequence(), '%n%' => $total], critical: self::NEAR_DEADLINE, sending: FilingPanelState::STEP_CURRENT, sendingSummary: 'retrying');
        }

        if (in_array($status, [FilingDeliveryStatus::QUEUED, FilingDeliveryStatus::SENDING, FilingDeliveryStatus::PARTIALLY_SENT], true)) {
            $next = $submission->firstUnacceptedPart();

            return $this->state('sending', FilingPanelState::TONE_BLUE, null, $in->cancellable ? [self::ACTION_CANCEL] : [], [], titleParams: ['%k%' => $next?->getSequence() ?? $total, '%n%' => $total], sending: FilingPanelState::STEP_CURRENT, sendingSummary: 'in_progress');
        }

        if ($status === FilingDeliveryStatus::SENT) {
            return $this->sentState($in, $submission);
        }

        if ($status === FilingDeliveryStatus::CANCELLED) {
            return $this->afterFailure($in, 'cancelled', [], FilingPanelState::TONE_NEUTRAL);
        }

        $kind = match ($status) {
            FilingDeliveryStatus::FAILED_TEMPORARY_EXHAUSTED => 'exhausted',
            FilingDeliveryStatus::FAILED_PERMANENT => ($in->failure['address_invalidated'] ?? false) ? 'permanent_address' : 'permanent_other',
            FilingDeliveryStatus::FAILED_SIZE => 'size',
            default => 'internal',
        };

        return $this->afterFailure($in, $kind, ['%k%' => $failurePart?->getSequence() ?? 1, '%n%' => $total]);
    }

    /**
     * A transmission that ended without the petition leaving (or, for an annex, with
     * the rest still to decide): the way forward is sending again when the guard
     * allows it, the remaining annexes, or another channel.
     *
     * @param array<string, string|int> $params
     *
     * @return array<string, mixed>
     */
    private function afterFailure(FilingPanelInput $in, string $code, array $params, string $tone = FilingPanelState::TONE_AMBER): array
    {
        if ($in->resumable || $in->closable) {
            return $this->state(
                $code,
                $tone,
                $in->resumable ? self::ACTION_RESUME : self::ACTION_CLOSE_REMAINING,
                $in->resumable && $in->closable ? [self::ACTION_CLOSE_REMAINING] : [],
                ['filing.failure.deadline_warning', self::RESPONSIBILITY],
                titleParams: $params,
                critical: 'filing.close.warning',
                sending: FilingPanelState::STEP_PROBLEM,
                sendingSummary: 'annex_failed',
            );
        }

        $primary = $this->resendAction($in);
        $secondary = [];
        if ($code === 'size' && $in->resplittable) {
            $secondary[] = $primary !== null ? $primary : self::ACTION_OTHER_CHANNEL_ARCHIVE;
            $primary = self::ACTION_RESPLIT;
        } elseif ($code === 'size' && $in->resplitDone) {
            $primary = $this->signingAction($in->package) ?? $primary;
        } elseif ($code === 'permanent_address') {
            $secondary[] = self::ACTION_COURT_ADDRESSES;
            $primary = self::ACTION_OTHER_CHANNEL_ARCHIVE;
        }
        if ($primary === null) {
            $primary = self::ACTION_OTHER_CHANNEL_ARCHIVE;
        } elseif ($primary !== self::ACTION_OTHER_CHANNEL_ARCHIVE && !in_array(self::ACTION_OTHER_CHANNEL_ARCHIVE, $secondary, true)) {
            $secondary[] = self::ACTION_OTHER_CHANNEL_ARCHIVE;
        }
        $secondary[] = self::ACTION_DECLARE_OTHER_CHANNEL;

        return $this->state(
            $code,
            $tone,
            $primary,
            array_slice(array_values(array_unique($secondary)), 0, 2),
            $code === 'cancelled' ? [self::RESPONSIBILITY] : ['filing.failure.deadline_warning', self::RESPONSIBILITY],
            titleParams: $params,
            critical: $code === 'cancelled' ? null : self::NEAR_DEADLINE,
            sending: $code === 'cancelled' ? FilingPanelState::STEP_TODO : FilingPanelState::STEP_PROBLEM,
            sendingSummary: $code === 'cancelled' ? 'cancelled' : 'failed',
        );
    }

    /** @return array<string, mixed> */
    private function sentState(FilingPanelInput $in, FilingSubmission $submission): array
    {
        $notes = ['filing.receipt.disclaimer', 'filing.panel.legal.auth_note', self::RESPONSIBILITY];
        if ($in->filedAt !== null) {
            return $this->state('filed', FilingPanelState::TONE_GREEN, null, [self::ACTION_EML_DOWNLOAD], $notes, titleParams: ['%date%' => $in->filedAt->format('d.m.Y')]);
        }

        $dateAction = $in->canSetFiledDate ? self::ACTION_SAVE_FILED_DATE : null;
        $sentAt = $submission->getSentAt()?->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i') ?? '-';

        return match ($submission->getEvidenceStatus()) {
            FilingEvidenceStatus::RECEIPT_RECEIVED => $this->state('receipt_received', FilingPanelState::TONE_BLUE, $dateAction, [self::ACTION_PORTAL_CHECK], $notes, explanationParams: [
                '%date%' => $submission->getCourtReceiptAt()?->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i') ?? '-',
            ]),
            FilingEvidenceStatus::RECEIPT_MANUAL => $this->state('receipt_manual', FilingPanelState::TONE_BLUE, $dateAction, [self::ACTION_PORTAL_CHECK], $notes),
            FilingEvidenceStatus::RECEIPT_OVERDUE => $this->state('receipt_overdue', FilingPanelState::TONE_AMBER, $in->receiptUploadable ? self::ACTION_UPLOAD_RECEIPT : null, array_values(array_filter([self::ACTION_PORTAL_CHECK, $dateAction])), $notes),
            default => $this->state('sent_no_receipt', FilingPanelState::TONE_BLUE, $dateAction, array_values(array_filter([self::ACTION_PORTAL_CHECK, $in->receiptUploadable ? self::ACTION_UPLOAD_RECEIPT : null])), $notes, explanationParams: ['%date%' => $sentAt]),
        };
    }

    /** @return array<string, mixed> */
    private function packageState(FilingPanelInput $in): array
    {
        $package = $in->package;
        $beforeFiling = in_array($in->caseStatus, [CaseStatus::AMIABIL, CaseStatus::SOMATIE_TRIMISA, CaseStatus::CERERE_GENERATA], true);
        $showBlocker = $in->gateBlocker !== null
            && !($in->gateBlocker === 'filing.package.blocked_wrong_status' && !in_array($in->caseStatus, [CaseStatus::AMIABIL, CaseStatus::SOMATIE_TRIMISA], true));

        if (!$beforeFiling) {
            if ($in->filedAt !== null) {
                return $this->state('filed', FilingPanelState::TONE_GREEN, null, [], [self::RESPONSIBILITY], titleParams: ['%date%' => $in->filedAt->format('d.m.Y')]);
            }

            return $this->state('inactive', FilingPanelState::TONE_NEUTRAL, null, [], []);
        }

        if ($package === null) {
            if ($showBlocker) {
                return $this->state('blocked', FilingPanelState::TONE_AMBER, self::ACTION_RESOLVE_BLOCKER, [self::ACTION_OTHER_CHANNEL_ARCHIVE], ['filing.package.compression_note'], explanationKey: $in->gateBlocker);
            }

            return $this->state('no_package', FilingPanelState::TONE_BLUE, $in->canBuild ? self::ACTION_BUILD : null, [self::ACTION_OTHER_CHANNEL_ARCHIVE, self::ACTION_DECLARE_OTHER_CHANNEL], ['filing.package.unsigned_instructions', 'filing.package.compression_note']);
        }

        if ($package->getStatus() === FilingPackageStatus::STALE) {
            return $this->state('stale', FilingPanelState::TONE_AMBER, $in->canBuild ? self::ACTION_REBUILD : null, [self::ACTION_OTHER_CHANNEL_ARCHIVE], self::SIGNING_NOTES, explanationKey: 'filing.package.stale_reason.' . ($package->getStaleReason() ?? 'other'));
        }

        if (in_array($package->getStatus(), [FilingPackageStatus::BUILT, FilingPackageStatus::AWAITING_SIGNATURES], true)) {
            [$ready, $total] = $this->signedCount($package);

            return $this->state('to_sign', FilingPanelState::TONE_BLUE, $this->signingAction($package), [self::ACTION_UNSIGNED_ZIP], self::SIGNING_NOTES, explanationParams: ['%ready%' => $ready, '%total%' => $total]);
        }

        $verdict = $in->verdict;
        if ($verdict !== null && $verdict->isAllowed()) {
            return $this->state('ready', FilingPanelState::TONE_BLUE, self::ACTION_VERIFY_SEND, [self::ACTION_OTHER_CHANNEL_ARCHIVE], self::SIGNING_NOTES);
        }

        return $this->state('send_blocked', FilingPanelState::TONE_AMBER, null, [self::ACTION_OTHER_CHANNEL_ARCHIVE, self::ACTION_DECLARE_OTHER_CHANNEL], self::SIGNING_NOTES);
    }

    /** Sending again needs a signed, current package the guard lets through; a stale one is rebuilt first. */
    private function resendAction(FilingPanelInput $in): ?string
    {
        if ($in->verdict !== null && $in->verdict->isAllowed()) {
            return self::ACTION_VERIFY_SEND;
        }
        if ($in->package?->getStatus() === FilingPackageStatus::STALE && $in->canBuild) {
            return self::ACTION_REBUILD;
        }

        return $this->signingAction($in->package);
    }

    private function signingAction(?FilingPackage $package): ?string
    {
        if ($package === null || !in_array($package->getStatus(), [FilingPackageStatus::BUILT, FilingPackageStatus::AWAITING_SIGNATURES], true)) {
            return null;
        }
        foreach ($package->getParts() as $part) {
            if ($part->isAwaitingProbableConfirmation()) {
                return self::ACTION_CONFIRM_PART;
            }
        }

        return self::ACTION_UPLOAD_PARTS;
    }

    /** @return array{0: int, 1: int} */
    private function signedCount(FilingPackage $package): array
    {
        $ready = 0;
        foreach ($package->getParts() as $part) {
            if ($part->isSignatureAccepted()) {
                ++$ready;
            }
        }

        return [$ready, $package->getParts()->count()];
    }

    /**
     * @param list<string>              $secondary
     * @param list<string>              $notes
     * @param array<string, string|int> $titleParams
     * @param array<string, string|int> $explanationParams
     *
     * @return array<string, mixed>
     */
    private function state(
        string $code,
        string $tone,
        ?string $primary,
        array $secondary,
        array $notes,
        ?string $titleKey = null,
        array $titleParams = [],
        ?string $explanationKey = null,
        array $explanationParams = [],
        ?string $critical = null,
        ?string $sending = null,
        ?string $sendingSummary = null,
        bool $legalOpen = false,
    ): array {
        return [
            'code' => $code,
            'tone' => $tone,
            'primary' => $primary,
            'secondary' => $secondary,
            'notes' => $notes,
            'titleKey' => $titleKey ?? 'filing.panel.title.' . $code,
            'titleParams' => $titleParams,
            'explanationKey' => $explanationKey ?? 'filing.panel.explanation.' . $code,
            'explanationParams' => $explanationParams,
            'critical' => $critical,
            'sending' => $sending,
            'sendingSummary' => $sendingSummary,
            'legalOpen' => $legalOpen,
        ];
    }

    /** @param array<string, mixed> $s */
    private function finish(FilingPanelInput $in, array $s): FilingPanelState
    {
        $allowed = function (?string $action) use ($in): bool {
            if ($action === null) {
                return false;
            }
            if (!$in->isOwner && in_array($action, self::OWNER_ONLY_ACTIONS, true)) {
                return false;
            }
            // The manual confirmation is the CERERE_GENERATA transition and is refused while a send holds the case.
            if ($action === self::ACTION_DECLARE_OTHER_CHANNEL) {
                return $in->caseStatus === CaseStatus::CERERE_GENERATA && !($in->submission?->isActive() ?? false);
            }

            return true;
        };

        $primary = $allowed($s['primary']) ? $s['primary'] : null;
        $secondary = array_values(array_filter($s['secondary'], static fn (string $a): bool => $a !== $primary));
        $secondary = array_slice(array_values(array_filter($secondary, $allowed)), 0, 2);

        return new FilingPanelState(
            code: $s['code'],
            tone: $s['tone'],
            titleKey: $s['titleKey'],
            titleParams: $s['titleParams'],
            explanationKey: $s['explanationKey'],
            explanationParams: $s['explanationParams'],
            primaryAction: $primary,
            secondaryActions: $secondary,
            steps: $this->steps($in, $s),
            tabDot: $this->dot($s['code'], $s['tone'], $primary),
            legalNotesOpen: $s['legalOpen'],
            legalNoteKeys: $s['notes'],
            criticalNoteKey: $s['critical'],
        );
    }

    /** No dot while nothing waits for the lawyer: sending, retrying, filed or no longer relevant. */
    private function dot(string $code, string $tone, ?string $primary): ?string
    {
        if (in_array($code, ['sending', 'retrying', 'filed', 'inactive', 'petition_returned_closed'], true)) {
            return null;
        }
        if ($tone === FilingPanelState::TONE_RED || $tone === FilingPanelState::TONE_AMBER) {
            return $tone;
        }

        return $primary !== null ? FilingPanelState::TONE_BLUE : null;
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return list<array{key: string, state: string, summaryKey: string, summaryParams: array<string, string|int>}>
     */
    private function steps(FilingPanelInput $in, array $s): array
    {
        $package = $in->package;
        $submission = $in->submission;
        $prefix = 'filing.panel.step.summary.';

        if ($package === null) {
            $packageStep = $this->step('package', FilingPanelState::STEP_CURRENT, $prefix . 'not_built');
        } elseif ($package->getStatus() === FilingPackageStatus::STALE) {
            $packageStep = $this->step('package', FilingPanelState::STEP_PROBLEM, $prefix . 'stale', ['%version%' => $package->getVersion()]);
        } else {
            $packageStep = $this->step('package', FilingPanelState::STEP_DONE, $prefix . 'version', ['%version%' => $package->getVersion()]);
        }

        if ($package === null || $package->getStatus() === FilingPackageStatus::STALE) {
            $signingStep = $this->step('signing', FilingPanelState::STEP_TODO, $prefix . 'after_package');
        } elseif (in_array($package->getStatus(), [FilingPackageStatus::BUILT, FilingPackageStatus::AWAITING_SIGNATURES], true)) {
            [$ready, $total] = $this->signedCount($package);
            $signingStep = $this->step('signing', FilingPanelState::STEP_CURRENT, $prefix . 'ready_count', ['%ready%' => $ready, '%total%' => $total]);
        } else {
            $signingStep = $this->step('signing', FilingPanelState::STEP_DONE, $prefix . 'all_signed');
        }

        $sentAt = $submission?->getSentAt();
        if ($s['sending'] !== null) {
            $sendingStep = $this->step('sending', $s['sending'], $prefix . $s['sendingSummary']);
        } elseif ($sentAt !== null && $submission !== null && $this->submissionDominates($in)) {
            $sendingStep = $this->step('sending', FilingPanelState::STEP_DONE, $prefix . 'accepted_at', ['%date%' => $sentAt->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i')]);
        } else {
            $sendingStep = $this->step('sending', FilingPanelState::STEP_TODO, $prefix . 'after_signing');
        }

        if ($in->filedAt !== null) {
            $dateStep = $this->step('filed_date', FilingPanelState::STEP_DONE, $prefix . 'declared', ['%date%' => $in->filedAt->format('d.m.Y')]);
        } elseif ($sendingStep['state'] === FilingPanelState::STEP_DONE && $sentAt !== null) {
            $dateStep = $this->step('filed_date', FilingPanelState::STEP_CURRENT, $prefix . 'not_declared');
        } else {
            $dateStep = $this->step('filed_date', FilingPanelState::STEP_TODO, $prefix . 'after_sending');
        }

        return [$packageStep, $signingStep, $sendingStep, $dateStep];
    }

    /**
     * @param array<string, string|int> $params
     *
     * @return array{key: string, state: string, summaryKey: string, summaryParams: array<string, string|int>}
     */
    private function step(string $key, string $state, string $summaryKey, array $params = []): array
    {
        return ['key' => $key, 'state' => $state, 'summaryKey' => $summaryKey, 'summaryParams' => $params];
    }
}
