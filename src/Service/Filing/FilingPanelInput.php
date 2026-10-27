<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingEmailPart;
use App\Entity\FilingPackage;
use App\Entity\FilingSubmission;
use App\Enum\CaseStatus;

/**
 * Everything the filing tab's status panel decides from, already computed by the
 * overview context: the resolver reads these facts and never queries anything.
 */
final readonly class FilingPanelInput
{
    /**
     * @param array{part: FilingEmailPart, kind: string, code: ?int, enhanced: ?string, next_retry_at: ?\DateTimeImmutable, address_invalidated: bool, address_reported: bool, address_report_open: bool}|null $failure
     */
    public function __construct(
        public CaseStatus $caseStatus,
        public ?\DateTimeInterface $filedAt,
        public ?FilingPackage $package,
        public ?FilingSubmission $submission,
        public ?string $gateBlocker = null,
        public bool $canBuild = false,
        public ?FilingSendVerdict $verdict = null,
        public bool $stalled = false,
        public ?FilingEmailPart $uncertainPart = null,
        public ?array $failure = null,
        public bool $lateReview = false,
        public bool $resumable = false,
        public bool $closable = false,
        public bool $cancellable = false,
        public bool $resplittable = false,
        public bool $resplitDone = false,
        public bool $canSetFiledDate = false,
        public bool $receiptUploadable = false,
        public bool $isOwner = false,
    ) {}
}
