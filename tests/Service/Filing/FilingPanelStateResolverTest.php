<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\Court;
use App\Entity\FilingEmailPart;
use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingEvidenceStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartRole;
use App\Enum\FilingPartStatus;
use App\Enum\OpisPlacement;
use App\Enum\SignatureStatus;
use App\Service\Filing\FilingPanelInput;
use App\Service\Filing\FilingPanelState;
use App\Service\Filing\FilingPanelStateResolver as R;
use App\Service\Filing\FilingSendVerdict;
use App\Service\Filing\UncertainPartResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilingPanelStateResolverTest extends TestCase
{
    private LegalCase $case;
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->case = new LegalCase();
    }

    public function testWithoutPackageTheBuildIsOfferedWithTheOtherChannel(): void
    {
        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, null, null, canBuild: true, isOwner: true));

        self::assertSame('no_package', $state->code);
        self::assertSame(R::ACTION_BUILD, $state->primaryAction);
        self::assertSame([R::ACTION_OTHER_CHANNEL_ARCHIVE, R::ACTION_DECLARE_OTHER_CHANNEL], $state->secondaryActions);
        self::assertSame('blue', $state->tabDot);
        self::assertSame(['package' => 'current', 'signing' => 'todo', 'sending' => 'todo', 'filed_date' => 'todo'], $this->stepStates($state));
    }

    public function testAGateBlockerExplainsWhyThePackageCannotBeBuilt(): void
    {
        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, null, null, gateBlocker: 'filing.package.blocked_petition_missing', isOwner: true));

        self::assertSame('blocked', $state->code);
        self::assertSame('filing.package.blocked_petition_missing', $state->explanationKey);
        self::assertSame(R::ACTION_RESOLVE_BLOCKER, $state->primaryAction);
        self::assertSame('amber', $state->tabDot);
    }

    public function testAStalePackageAsksForARebuildWithItsReason(): void
    {
        $package = $this->package(FilingPackageStatus::STALE);
        $package->markStale('power_of_attorney');

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, null, canBuild: true, isOwner: true));

        self::assertSame('stale', $state->code);
        self::assertSame(R::ACTION_REBUILD, $state->primaryAction);
        self::assertSame('filing.package.stale_reason.power_of_attorney', $state->explanationKey);
        self::assertSame('problem', $this->stepStates($state)['package']);
    }

    public function testFilesToSignCountTheDetectedSignaturesAndOfferTheOtherChannel(): void
    {
        $package = $this->package(FilingPackageStatus::AWAITING_SIGNATURES, signed: 1, total: 3);

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, null, isOwner: true));

        self::assertSame('to_sign', $state->code);
        self::assertSame(R::ACTION_UPLOAD_PARTS, $state->primaryAction);
        // The unsigned .zip is in the header of the signing card; the panel keeps the other channel.
        self::assertSame([R::ACTION_OTHER_CHANNEL_ARCHIVE], $state->secondaryActions);
        self::assertSame(['%ready%' => 1, '%total%' => 3], $state->explanationParams);
        self::assertSame('current', $this->stepStates($state)['signing']);
    }

    public function testATextOnlyMatchIsConfirmedBeforeAnythingElse(): void
    {
        $package = $this->package(FilingPackageStatus::AWAITING_SIGNATURES, signed: 0, total: 2);
        $package->getParts()->first()->recordSignedUpload('x.pdf', str_repeat('a', 64), 10, $this->user, SignatureStatus::PRESENT_PROBABLE, ['warnings' => []]);

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, null, isOwner: true));

        self::assertSame(R::ACTION_CONFIRM_PART, $state->primaryAction);
    }

    public function testAnotherViewerGetsNoDownloadOrSigningAction(): void
    {
        $package = $this->package(FilingPackageStatus::AWAITING_SIGNATURES, signed: 0, total: 2);

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, null, isOwner: false));

        self::assertSame('to_sign', $state->code);
        self::assertNull($state->primaryAction);
        self::assertSame([], $state->secondaryActions);
    }

    public function testASignedPackageTheGuardLetsThroughIsReadyToSend(): void
    {
        $package = $this->package(FilingPackageStatus::SIGNED);

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, null, verdict: $this->allowed(), isOwner: true));

        self::assertSame('ready', $state->code);
        self::assertSame(R::ACTION_VERIFY_SEND, $state->primaryAction);
        self::assertSame('blue', $state->tabDot);
        self::assertSame('done', $this->stepStates($state)['signing']);
    }

    public function testASignedPackageWithBlockersSaysSendingIsNotAvailable(): void
    {
        $package = $this->package(FilingPackageStatus::SIGNED);

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, null, verdict: new FilingSendVerdict(['filing.send.blocker.address_missing'], [], null, $package), isOwner: true));

        self::assertSame('send_blocked', $state->code);
        self::assertNull($state->primaryAction);
        self::assertSame('amber', $state->tabDot);
    }

    public function testWhileSendingNothingWaitsForTheLawyer(): void
    {
        [$package, $submission] = $this->submission(2);

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, $submission, cancellable: true, isOwner: true));

        self::assertSame('sending', $state->code);
        self::assertSame(['%k%' => 1, '%n%' => 2], $state->titleParams);
        self::assertNull($state->primaryAction);
        self::assertSame([R::ACTION_CANCEL], $state->secondaryActions);
        self::assertNull($state->tabDot);
        self::assertSame('current', $this->stepStates($state)['sending']);
    }

    public function testARetryNeedsNoActionButKeepsTheDeadlinePrecaution(): void
    {
        [$package, $submission] = $this->submission(1);
        $part = $submission->getPart(1);
        $part->scheduleRetry(451, '4.3.0', 'busy');

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, $submission, failure: $this->failure($part, 'temporary'), isOwner: true));

        self::assertSame('retrying', $state->code);
        self::assertNull($state->primaryAction);
        self::assertNull($state->tabDot);
        self::assertSame('filing.panel.legal.near_deadline', $state->criticalNoteKey);
    }

    public function testAStalledSendComesBeforeARetry(): void
    {
        [$package, $submission] = $this->submission(2);
        $submission->getPart(1)->markAccepted(new \DateTimeImmutable());
        $retrying = $submission->getPart(2);
        $retrying->scheduleRetry(451, '4.3.0', 'busy');

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_DEPUSA, null, $package, $submission, stalled: true, failure: $this->failure($retrying, 'temporary'), resumable: true, closable: true, isOwner: true));

        self::assertSame('stalled', $state->code);
        self::assertSame(R::ACTION_RESUME, $state->primaryAction);
        self::assertSame([R::ACTION_CLOSE_REMAINING], $state->secondaryActions);
        self::assertSame('filing.close.warning', $state->criticalNoteKey);
        self::assertSame('amber', $state->tabDot);
    }

    /** The emails after a refused one stay pending with nothing queued: the send waits for the lawyer. */
    public function testARefusedAnnexWithLaterEmailsPendingAsksToResumeOrClose(): void
    {
        [$package, $submission] = $this->submission(3);
        $submission->getPart(1)->markAccepted(new \DateTimeImmutable());
        $refused = $submission->getPart(2);
        $refused->recordFailure(FilingPartStatus::FAILED_PERMANENT, 554, null, 'refused');

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_DEPUSA, null, $package, $submission, failure: $this->failure($refused, 'permanent'), resumable: true, closable: true, isOwner: true));

        self::assertSame('stalled', $state->code);
        self::assertSame(R::ACTION_RESUME, $state->primaryAction);
        self::assertSame([R::ACTION_CLOSE_REMAINING], $state->secondaryActions);
    }

    public function testALateBounceReviewComesBeforeEverythingElse(): void
    {
        [$package, $submission] = $this->submission(2);
        $submission->getPart(1)->markAccepted(new \DateTimeImmutable());

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_DEPUSA, new \DateTimeImmutable('2026-10-20'), $package, $submission, stalled: true, lateReview: true, resumable: true, isOwner: true));

        self::assertSame('late_review', $state->code);
        self::assertSame('filing.undelivered.late_headline', $state->titleKey);
        self::assertSame(R::ACTION_LATE_ACK, $state->primaryAction);
        self::assertFalse($state->legalNotesOpen);
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function undeliveredReports(): iterable
    {
        yield 'unknown mailbox' => ['5.1.1', 'filing.panel.title.undelivered_unknown_address'];
        yield 'unknown domain' => ['5.1.2', 'filing.panel.title.undelivered_unknown_address'];
        yield 'no route' => ['5.4.4', 'filing.panel.title.undelivered_unknown_address'];
        yield 'mailbox full' => ['5.2.2', 'filing.panel.title.undelivered'];
        yield 'policy' => ['5.7.1', 'filing.panel.title.undelivered'];
        yield 'no code' => [null, 'filing.panel.title.undelivered'];
    }

    #[DataProvider('undeliveredReports')]
    public function testAnUndeliveredPetitionSaysTheAddressDoesNotExistOnlyWhenTheReportSaysSo(?string $enhanced, string $titleKey): void
    {
        [$package, $submission] = $this->submission(1);
        $part = $submission->getPart(1);
        $part->markAccepted(new \DateTimeImmutable());
        $part->recordAsyncFailure(FilingPartStatus::FAILED_PERMANENT, $enhanced, 'Delivery report');
        $submission->markUndelivered();

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, $submission, failure: $this->failure($part, 'permanent', $enhanced), isOwner: true));

        self::assertSame('undelivered', $state->code);
        self::assertSame($titleKey, $state->titleKey);
        self::assertSame('red', $state->tabDot);
        self::assertTrue($state->legalNotesOpen);
        self::assertSame('filing.panel.legal.deadline_short', $state->criticalNoteKey);
        self::assertContains('filing.undelivered.deadline_warning', $state->legalNoteKeys);
        self::assertSame(R::ACTION_OTHER_CHANNEL_ARCHIVE, $state->primaryAction);
    }

    public function testAnotherViewerOfAnUndeliveredPetitionGetsNoArchive(): void
    {
        [$package, $submission] = $this->submission(1);
        $part = $submission->getPart(1);
        $part->recordAsyncFailure(FilingPartStatus::FAILED_PERMANENT, '5.1.1', 'Delivery report');
        $submission->markUndelivered();

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, $submission, isOwner: false));

        self::assertNull($state->primaryAction);
        self::assertNotContains(R::ACTION_OTHER_CHANNEL_ARCHIVE, $state->secondaryActions);
    }

    public function testOnlyAnUndeliveredPetitionOpensTheLegalNotes(): void
    {
        [$package, $submission] = $this->submission(1);
        $submission->getPart(1)->markAccepted(new \DateTimeImmutable('2026-10-24 10:00'));
        $submission->setSentAt(new \DateTimeImmutable('2026-10-24 10:00'));
        $submission->setDeliveryStatus(FilingDeliveryStatus::SENT);
        $submission->release();

        $inputs = [
            new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, null, null, canBuild: true, isOwner: true),
            new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $this->package(FilingPackageStatus::SIGNED), null, verdict: $this->allowed(), isOwner: true),
            new FilingPanelInput(CaseStatus::CERERE_DEPUSA, null, $package, $submission, canSetFiledDate: true, isOwner: true),
        ];
        foreach ($inputs as $input) {
            self::assertFalse($this->resolve($input)->legalNotesOpen);
        }
    }

    public function testAnUncertainEmailAsksForTheCcDeclaration(): void
    {
        [$package, $submission] = $this->submission(1);
        $part = $submission->getPart(1);
        $part->setStatus(FilingPartStatus::UNCERTAIN);
        $submission->markNeedsReview('filing.status.review.part_uncertain');

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, $submission, uncertainPart: $part, failure: $this->failure($part, 'uncertain'), isOwner: true));

        self::assertSame('uncertain', $state->code);
        self::assertSame(R::ACTION_CC_DECLARE, $state->primaryAction);
        self::assertSame('amber', $state->tabDot);
        self::assertSame('problem', $this->stepStates($state)['sending']);
    }

    public function testAStoppedSendBeforeThePetitionKeepsTheDeadlineSentenceVisible(): void
    {
        [$package, $submission] = $this->submission(2);
        $submission->markNeedsReview('filing.status.review.part_uncertain');

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, $submission, resumable: true, isOwner: true));

        self::assertSame('review', $state->code);
        self::assertSame(R::ACTION_RESUME, $state->primaryAction);
        self::assertSame('filing.panel.legal.near_deadline', $state->criticalNoteKey);

        $submission->getPart(1)->markAccepted(new \DateTimeImmutable());
        $afterPetition = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_DEPUSA, null, $package, $submission, resumable: true, isOwner: true));

        self::assertSame('review_after_petition', $afterPetition->code);
        self::assertSame('filing.close.warning', $afterPetition->criticalNoteKey);
    }

    public function testThePetitionDeclaredNotReceivedInCcIsNotAGenericInternalError(): void
    {
        [$package, $submission] = $this->submission(2);
        $part = $submission->getPart(1);
        $part->recordFailure(FilingPartStatus::FAILED_INTERNAL, null, null, UncertainPartResolver::NOT_RECEIVED_ERROR);
        $submission->failTerminal(FilingDeliveryStatus::FAILED_INTERNAL);

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, $submission, verdict: $this->allowed(), failure: $this->failure($part, 'internal'), isOwner: true));

        self::assertSame('cc_not_received_petition', $state->code);
        self::assertSame(R::ACTION_VERIFY_SEND, $state->primaryAction);
        self::assertContains(R::ACTION_DECLARE_OTHER_CHANNEL, $state->secondaryActions);

        $part->recordFailure(FilingPartStatus::FAILED_INTERNAL, null, null, 'filing.status.review.signed_file_changed');
        $generic = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, $submission, verdict: $this->allowed(), failure: $this->failure($part, 'internal'), isOwner: true));
        self::assertSame('internal', $generic->code);
    }

    public function testAnAnnexDeclaredNotReceivedLeavesTheResumeOrTheClose(): void
    {
        [$package, $submission] = $this->submission(2);
        $submission->getPart(1)->markAccepted(new \DateTimeImmutable());
        $annex = $submission->getPart(2);
        $annex->recordFailure(FilingPartStatus::FAILED_INTERNAL, null, null, UncertainPartResolver::NOT_RECEIVED_ERROR);

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_DEPUSA, null, $package, $submission, stalled: true, failure: $this->failure($annex, 'internal'), resumable: true, closable: true, isOwner: true));

        self::assertSame('cc_not_received_annex', $state->code);
        self::assertSame(R::ACTION_RESUME, $state->primaryAction);
        self::assertSame([R::ACTION_CLOSE_REMAINING], $state->secondaryActions);
    }

    public function testAnAdminOverrideIsNamedAsSuch(): void
    {
        [$package, $submission] = $this->submission(1);
        $part = $submission->getPart(1);
        $part->recordFailure(FilingPartStatus::FAILED_INTERNAL, null, null, UncertainPartResolver::OVERRIDE_FAILED_ERROR);
        $submission->failTerminal(FilingDeliveryStatus::FAILED_INTERNAL);

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, $submission, verdict: $this->allowed(), failure: $this->failure($part, 'internal'), isOwner: true));

        self::assertSame('admin_failed', $state->code);
        self::assertSame(R::ACTION_VERIFY_SEND, $state->primaryAction);
    }

    /** @return iterable<string, array{FilingDeliveryStatus, FilingPartStatus, bool, string}> */
    public static function failures(): iterable
    {
        yield 'temporary exhausted' => [FilingDeliveryStatus::FAILED_TEMPORARY_EXHAUSTED, FilingPartStatus::FAILED_TEMPORARY_EXHAUSTED, false, 'exhausted'];
        yield 'address rejected' => [FilingDeliveryStatus::FAILED_PERMANENT, FilingPartStatus::FAILED_PERMANENT, true, 'permanent_address'];
        yield 'other refusal' => [FilingDeliveryStatus::FAILED_PERMANENT, FilingPartStatus::FAILED_PERMANENT, false, 'permanent_other'];
        yield 'size' => [FilingDeliveryStatus::FAILED_SIZE, FilingPartStatus::FAILED_SIZE, false, 'size'];
        yield 'internal' => [FilingDeliveryStatus::FAILED_INTERNAL, FilingPartStatus::FAILED_INTERNAL, false, 'internal'];
    }

    #[DataProvider('failures')]
    public function testAFailedSendNamesTheFailureAndKeepsTheDeadlinePrecaution(FilingDeliveryStatus $status, FilingPartStatus $partStatus, bool $addressInvalidated, string $code): void
    {
        [$package, $submission] = $this->submission(1);
        $part = $submission->getPart(1);
        $part->recordFailure($partStatus, 550, '5.0.0', 'refused');
        $submission->failTerminal($status);
        $failure = $this->failure($part, 'permanent');
        $failure['address_invalidated'] = $addressInvalidated;

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, $submission, verdict: $this->allowed(), failure: $failure, isOwner: true));

        self::assertSame($code, $state->code);
        self::assertSame('amber', $state->tone);
        self::assertSame('filing.panel.legal.near_deadline', $state->criticalNoteKey);
        self::assertLessThanOrEqual(2, count($state->secondaryActions));
        self::assertNotNull($state->primaryAction);
    }

    public function testASizeRefusalOffersTheSplitWhenItCanHelp(): void
    {
        [$package, $submission] = $this->submission(1);
        $part = $submission->getPart(1);
        $part->recordFailure(FilingPartStatus::FAILED_SIZE, 552, '5.3.4', 'too big');
        $submission->failTerminal(FilingDeliveryStatus::FAILED_SIZE);

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, $submission, verdict: $this->allowed(), failure: $this->failure($part, 'size'), resplittable: true, isOwner: true));

        self::assertSame('size', $state->code);
        self::assertSame(R::ACTION_RESPLIT, $state->primaryAction);
    }

    public function testACancelledSendCanBeSentAgain(): void
    {
        [$package, $submission] = $this->submission(1);
        $submission->getPart(1)->setStatus(FilingPartStatus::CANCELLED);
        $submission->markCancelled(new \DateTimeImmutable());

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $package, $submission, verdict: $this->allowed(), isOwner: true));

        self::assertSame('cancelled', $state->code);
        self::assertSame(R::ACTION_VERIFY_SEND, $state->primaryAction);
        self::assertNull($state->criticalNoteKey);
    }

    public function testAPackageRebuiltAfterACancelTakesThePanelBack(): void
    {
        [$package, $submission] = $this->submission(1);
        $submission->getPart(1)->setStatus(FilingPartStatus::CANCELLED);
        $submission->markCancelled(new \DateTimeImmutable());
        $this->setId($package, 1);
        $rebuilt = $this->package(FilingPackageStatus::BUILT, signed: 0, total: 2);
        $this->setId($rebuilt, 2);

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_GENERATA, null, $rebuilt, $submission, isOwner: true));

        self::assertSame('to_sign', $state->code);
    }

    public function testASentPetitionWaitsForItsDateAndAReceiptComesFirst(): void
    {
        [$package, $submission] = $this->sentSubmission();

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_DEPUSA, null, $package, $submission, canSetFiledDate: true, receiptUploadable: true, isOwner: true));
        self::assertSame('sent_no_receipt', $state->code);
        self::assertSame(R::ACTION_SAVE_FILED_DATE, $state->primaryAction);
        self::assertSame([R::ACTION_PORTAL_CHECK, R::ACTION_UPLOAD_RECEIPT], $state->secondaryActions);
        self::assertSame('blue', $state->tabDot);
        self::assertSame(['package' => 'done', 'signing' => 'done', 'sending' => 'done', 'filed_date' => 'current'], $this->stepStates($state));

        $submission->markReceipt(new \DateTimeImmutable('2026-10-24 10:05'), null, FilingEvidenceStatus::RECEIPT_RECEIVED);
        $received = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_DEPUSA, null, $package, $submission, canSetFiledDate: true, isOwner: true));
        self::assertSame('receipt_received', $received->code);
        self::assertSame(R::ACTION_SAVE_FILED_DATE, $received->primaryAction);
    }

    public function testAnOverdueAndAnUploadedReceiptHaveTheirOwnState(): void
    {
        [$package, $submission] = $this->sentSubmission();
        $submission->markReceiptOverdue();
        self::assertSame('receipt_overdue', $this->resolve(new FilingPanelInput(CaseStatus::CERERE_DEPUSA, null, $package, $submission, receiptUploadable: true, isOwner: true))->code);

        $submission->markReceipt(null, null, FilingEvidenceStatus::RECEIPT_MANUAL);
        self::assertSame('receipt_manual', $this->resolve(new FilingPanelInput(CaseStatus::CERERE_DEPUSA, null, $package, $submission, isOwner: true))->code);
    }

    public function testADeclaredDateIsShownAsDeclaredWithNoDot(): void
    {
        [$package, $submission] = $this->sentSubmission();

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_DEPUSA, new \DateTimeImmutable('2026-10-24'), $package, $submission, canSetFiledDate: true, isOwner: true));

        self::assertSame('filed', $state->code);
        self::assertSame(['%date%' => '24.10.2026'], $state->titleParams);
        self::assertSame('green', $state->tone);
        self::assertNull($state->tabDot);
        self::assertSame('done', $this->stepStates($state)['filed_date']);
    }

    public function testAClosedPartialSendWarnsAboutTheRemainingAnnexes(): void
    {
        [$package, $submission] = $this->submission(2);
        $submission->getPart(1)->markAccepted(new \DateTimeImmutable());
        $submission->setSentAt(new \DateTimeImmutable());
        $submission->getPart(2)->setStatus(FilingPartStatus::CANCELLED);
        $submission->closePartial(new \DateTimeImmutable());

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_DEPUSA, null, $package, $submission, canSetFiledDate: true, isOwner: true));

        self::assertSame('closed_partial', $state->code);
        self::assertSame('filing.close.warning', $state->criticalNoteKey);
        self::assertSame(R::ACTION_SAVE_FILED_DATE, $state->primaryAction);
    }

    public function testAPetitionReturnedAfterTheCheckStaysClosed(): void
    {
        [$package, $submission] = $this->sentSubmission();
        $submission->getPart(1)->recordAsyncFailure(FilingPartStatus::FAILED_PERMANENT, '5.1.1', 'Delivery report');

        $state = $this->resolve(new FilingPanelInput(CaseStatus::CERERE_DEPUSA, new \DateTimeImmutable('2026-10-24'), $package, $submission, isOwner: true));

        self::assertSame('petition_returned_closed', $state->code);
        self::assertNull($state->tabDot);
    }

    private function resolve(FilingPanelInput $input): FilingPanelState
    {
        return (new R())->resolve($input);
    }

    /** @return array<string, string> */
    private function stepStates(FilingPanelState $state): array
    {
        $states = [];
        foreach ($state->steps as $step) {
            $states[$step['key']] = $step['state'];
        }

        return $states;
    }

    private function allowed(): FilingSendVerdict
    {
        return new FilingSendVerdict([], [], null, null);
    }

    private function package(FilingPackageStatus $status, int $signed = 2, int $total = 2): FilingPackage
    {
        $package = new FilingPackage($this->case, 1, OpisPlacement::MERGED, $this->user);
        for ($i = 1; $i <= $total; ++$i) {
            $part = new FilingPackagePart(FilingPartRole::PETITION, $i, 'Part ' . $i, 1, 'p' . $i . '.pdf', str_repeat('0', 64), 100, 'token' . $i);
            if ($i <= $signed) {
                $part->recordSignedUpload('s' . $i . '.pdf', str_repeat('1', 64), 120, $this->user, SignatureStatus::PRESENT_MATCHED, ['warnings' => []]);
            }
            $package->addPart($part);
        }
        $package->setStatus($status);

        return $package;
    }

    /** @return array{0: FilingPackage, 1: FilingSubmission} */
    private function submission(int $emails): array
    {
        $package = $this->package(FilingPackageStatus::SIGNED);
        $submission = new FilingSubmission($this->case, $package, new Court(), $this->user, 'token', 'v1', new \DateTimeImmutable());
        $submission->setAddresses('court@example.test', 'court@example.test', 'from@example.test', 'lawyer@example.test', 'lawyer@example.test', 'bounce@example.test');
        for ($k = 1; $k <= $emails; ++$k) {
            new FilingEmailPart($submission, $k, $emails, 'Subject', 'mid' . $k, 'verp' . $k);
        }

        return [$package, $submission];
    }

    /** @return array{0: FilingPackage, 1: FilingSubmission} */
    private function sentSubmission(): array
    {
        [$package, $submission] = $this->submission(1);
        $at = new \DateTimeImmutable('2026-10-24 10:00');
        $submission->getPart(1)->markAccepted($at);
        $submission->setSentAt($at);
        $submission->setDeliveryStatus(FilingDeliveryStatus::SENT);
        $submission->release();
        $package->setStatus(FilingPackageStatus::SENT);

        return [$package, $submission];
    }

    /** @return array{part: FilingEmailPart, kind: string, code: ?int, enhanced: ?string, next_retry_at: ?\DateTimeImmutable, address_invalidated: bool, address_reported: bool, address_report_open: bool} */
    private function failure(FilingEmailPart $part, string $kind, ?string $enhanced = null): array
    {
        return [
            'part' => $part,
            'kind' => $kind,
            'code' => $part->getLastSmtpCode(),
            'enhanced' => $enhanced ?? $part->getLastEnhancedStatus(),
            'next_retry_at' => null,
            'address_invalidated' => false,
            'address_reported' => false,
            'address_report_open' => false,
        ];
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
