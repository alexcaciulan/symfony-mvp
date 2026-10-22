<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\AppSetting;
use App\Entity\Document;
use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartRole;
use App\Enum\OpisPlacement;
use App\Enum\SignatureStatus;
use App\Repository\AppSettingRepository;
use App\Repository\FilingPackageRepository;
use App\Service\AuditLogService;
use App\Service\Document\MissingDocumentFileException;
use App\Service\Pdf\PdfToolException;
use App\Service\Pdf\ProcessSandbox;
use App\Service\Pdf\QpdfRunner;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the unsigned files of a court filing: the petition as it was generated
 * (never regenerated or rewritten), the package index, the annexes in one numbered
 * PDF per volume, and the power of attorney as its own file. Compression and the
 * split into volumes happen here, before signing, because a signed file must never
 * change again.
 *
 * The PDF work runs outside any transaction; only the final check and persist take
 * the lock on the case, so the case stays writable while the tools run.
 *
 * @phpstan-import-type AnnexRange from AnnexBundleBuilder
 */
final class FilingPackageBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilingPackageRepository $packages,
        private readonly AppSettingRepository $settings,
        private readonly FilingGate $gate,
        private readonly FilingAnnexSelector $selector,
        private readonly FilingSourceManifestBuilder $manifestBuilder,
        private readonly PackageOpisRenderer $opisRenderer,
        private readonly AnnexBundleBuilder $annexBundle,
        private readonly FilingPartWriter $parts,
        private readonly PetitionWarningsProbe $petitionWarnings,
        private readonly ProcessSandbox $sandbox,
        private readonly QpdfRunner $qpdf,
        private readonly AuditLogService $auditLogService,
        private readonly int $filingMaxEmailBytes,
        private readonly int $filingSignatureReserveBytes,
    ) {}

    public function opisPlacement(): OpisPlacement
    {
        return $this->settings->get(AppSetting::KEY_FILING_OPIS_PLACEMENT) === 'separate'
            ? OpisPlacement::SEPARATE
            : OpisPlacement::MERGED;
    }

    /**
     * Builds a new version, or returns the current one when nothing it depends on
     * changed, which makes a double click harmless.
     *
     * @throws FilingPackageBlockedException a gate or a size limit refuses the build
     * @throws PdfToolException              a document cannot be processed
     * @throws MissingDocumentFileException  a mandatory file is gone from storage
     */
    public function build(LegalCase $case, User $user): FilingPackage
    {
        $blocker = $this->gate->firstBlocker($case);
        if ($blocker !== null) {
            throw new FilingPackageBlockedException($blocker);
        }
        $this->assertLawyerNamed($case);

        $placement = $this->opisPlacement();
        $selection = $this->selector->select($case);
        $manifest = $this->manifestBuilder->build($case, $selection, $placement);
        $fingerprint = FilingPackageFingerprint::hash($manifest);

        $current = $this->packages->findCurrentForCase($case);
        if ($this->isReusable($current, $fingerprint)) {
            return $current;
        }

        // Read without the lock: commit() checks the version is still free before persisting.
        $version = $this->packages->nextVersion($case);
        $written = [];
        $this->sandbox->begin();
        try {
            $package = $this->assemble($case, $user, $selection, $placement, $fingerprint, $manifest, $version, $written);

            return $this->commit($package, $current, $written, 'filing_package_built', reuseMatching: true);
        } catch (\Throwable $e) {
            $this->parts->removeFiles($written);
            throw $e;
        } finally {
            $this->sandbox->end();
        }
    }

    /**
     * A new version with the same petition, index and power of attorney files
     * (signed or not) and the annexes split again for a smaller limit. Allowed only
     * when every annex keeps its pages in the continuous numbering: otherwise the
     * index, and with it the signed petition, no longer describes the annexes.
     */
    public function rebuildAnnexVolumes(FilingPackage $package, int $maxEmailBytes): FilingPackage
    {
        $case = $package->getLegalCase();
        $this->assertLawyerNamed($case);
        $previousRanges = $package->getSizePlan()['annexRanges'] ?? [];
        $annexes = $this->annexDocuments($package);

        $version = $this->packages->nextVersion($case);
        $written = [];
        $this->sandbox->begin();
        try {
            $normalized = $this->annexBundle->normalize($annexes);
            $ranges = $this->annexBundle->ranges($normalized);
            if ($this->annexBundle->rangesForStorage($ranges) != $previousRanges) {
                throw new FilingPackageBlockedException('filing.package.error.rebuild_ranges_changed');
            }

            $budget = FilingSizePlanner::budget($maxEmailBytes, $this->filingSignatureReserveBytes);
            $firstEmailBytes = 0;
            $kept = [];
            foreach ($package->getParts() as $part) {
                if ($part->getRole() !== FilingPartRole::ANNEX_BUNDLE) {
                    $kept[] = $part;
                    $firstEmailBytes += $part->getSignedBytes() ?? $part->getUnsignedBytes();
                }
            }

            [$plan, $normalized] = $this->annexBundle->planWithCompression($firstEmailBytes, $normalized, $budget);
            $relativeDir = $this->relativeDir($case, $version);

            $new = new FilingPackage($case, $version, $package->getOpisPlacement(), $package->getBuiltBy());
            $new->setSource($package->getSourceFingerprint(), $package->getSourceManifest());
            $new->setIncludesStampDutyProof($package->includesStampDutyProof());

            $position = 1;
            $anySigned = false;
            foreach ($kept as $part) {
                $new->addPart($part->copyForPackage($this->parts->token(), $position++));
                $anySigned = $anySigned || $part->getSignedPath() !== null;
            }
            [$plan, $volumeParts] = $this->annexBundle->buildVolumesWithinBudget($case, $plan, $normalized, $ranges, $relativeDir, $position, $written, $firstEmailBytes, $budget);
            foreach ($volumeParts as $part) {
                $new->addPart($part);
            }

            $new->setSizePlan($this->sizePlanData($plan, $new, $ranges, $package->getWarnings(), $package->getSkippedDocuments()));
            if ($anySigned) {
                $new->setStatus(FilingPackageStatus::AWAITING_SIGNATURES);
            }

            return $this->commit($new, $package, $written, 'filing_package_annexes_rebuilt', reuseMatching: false);
        } catch (\Throwable $e) {
            $this->parts->removeFiles($written);
            throw $e;
        } finally {
            $this->sandbox->end();
        }
    }

    /**
     * The annex documents of a package, in its order.
     *
     * @return list<Document>
     */
    private function annexDocuments(FilingPackage $package): array
    {
        $byId = [];
        foreach ($package->getLegalCase()->getDocuments() as $document) {
            $byId[$document->getId()] = $document;
        }

        $annexes = [];
        foreach ($package->partsWithRole(FilingPartRole::ANNEX_BUNDLE) as $part) {
            foreach ($part->getSourceDocumentIds() as $id) {
                if (!isset($byId[$id])) {
                    throw new FilingPackageBlockedException('filing.package.error.rebuild_ranges_changed');
                }
                $annexes[] = $byId[$id];
            }
        }

        return $annexes;
    }

    /**
     * Every annex volume carries a cover with the lawyer's statement; a nameless
     * statement must not reach a file the lawyer signs.
     */
    private function assertLawyerNamed(LegalCase $case): void
    {
        if (trim((string) $case->getUser()?->getFullName()) === '') {
            throw new FilingPackageBlockedException('filing.package.error.lawyer_name_missing');
        }
    }

    /** @phpstan-assert-if-true !null $current */
    private function isReusable(?FilingPackage $current, string $fingerprint): bool
    {
        return $current !== null
            && $current->getStatus() !== FilingPackageStatus::STALE
            && $current->getSourceFingerprint() === $fingerprint;
    }

    /**
     * Persists the new version under the case lock, provided no other build or
     * rebuild finished while the files were being prepared. The unique (case,
     * version) index is the second line if the lock is ever bypassed.
     *
     * @param list<string> $written
     */
    private function commit(FilingPackage $new, ?FilingPackage $expectedCurrent, array $written, string $auditAction, bool $reuseMatching): FilingPackage
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $case = $new->getLegalCase();
            $this->em->lock($case, LockMode::PESSIMISTIC_WRITE);

            $latest = $this->packages->findCurrentForCase($case);
            if ($this->packages->nextVersion($case) !== $new->getVersion() || $latest?->getId() !== $expectedCurrent?->getId()) {
                if (!$reuseMatching || !$this->isReusable($latest, $new->getSourceFingerprint())) {
                    throw new FilingPackageBlockedException('filing.package.error.concurrent_build');
                }
                // A concurrent double click built the same package first: keep that one.
                $connection->commit();
                $this->parts->removeFiles($written);

                return $latest;
            }

            if ($expectedCurrent !== null) {
                $expectedCurrent->setStatus(FilingPackageStatus::SUPERSEDED);
                $new->setPreviousVersion($expectedCurrent);
            }

            $this->em->persist($new);
            $this->auditBuilt($new, $auditAction);
            $this->em->flush();
            $connection->commit();

            return $new;
        } catch (UniqueConstraintViolationException $e) {
            $connection->rollBack();

            throw new FilingPackageBlockedException('filing.package.error.concurrent_build', [], $e);
        } catch (\Throwable $e) {
            // Leaves the EntityManager open, unlike wrapInTransaction: a refused build
            // is an expected outcome, and the request still renders the case afterwards.
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * The new version's files and its unsaved entity; persisting is left to commit().
     *
     * @param array<string, mixed> $manifest
     * @param list<string>         $written
     */
    private function assemble(
        LegalCase $case,
        User $user,
        FilingAnnexSelection $selection,
        OpisPlacement $placement,
        string $fingerprint,
        array $manifest,
        int $version,
        array &$written,
    ): FilingPackage {
        $relativeDir = $this->relativeDir($case, $version);
        $budget = FilingSizePlanner::budget($this->filingMaxEmailBytes, $this->filingSignatureReserveBytes);

        $petitionPath = $this->selector->path($selection->petition);
        $petitionPages = $this->qpdf->pageCount($petitionPath);
        $poaPdf = $this->annexBundle->toPdf($selection->powerOfAttorney);
        $normalized = $this->annexBundle->normalize($selection->annexes);
        $ranges = $this->annexBundle->ranges($normalized);
        $annexPageCount = $ranges === [] ? 0 : max(array_column($ranges, 'to'));

        // The index depends only on the ranges, which compression and the split do
        // not change (page counts are preserved), so it is rendered once, here.
        $opisPath = $this->sandbox->tempPath();
        $this->opisRenderer->render(
            $case,
            $placement,
            $selection->powerOfAttorney,
            array_map(static fn (array $r): array => ['document' => $r['document'], 'from' => $r['from'], 'to' => $r['to']], $ranges),
            $annexPageCount,
            $opisPath,
        );

        $parts = [];
        $position = 1;
        if ($placement === OpisPlacement::MERGED) {
            // The merged file is new bytes by nature; the petition's own pages and
            // text are unchanged and come first, the index is appended after them.
            $merged = $this->sandbox->tempPath();
            $this->qpdf->merge([$petitionPath, $opisPath], $merged);
            $parts[] = $this->parts->generatedPart(FilingPartRole::PETITION, $position++, $merged, $relativeDir, null, $written, [$selection->petition->getId()], $this->parts->title(FilingPartRole::PETITION, null, withOpis: true));
        } else {
            // Byte-for-byte copy: the lawyer signs exactly the petition that was generated.
            $parts[] = $this->parts->copiedPart(FilingPartRole::PETITION, $position++, $petitionPath, $relativeDir, $written, [$selection->petition->getId()], $petitionPages);
            $parts[] = $this->parts->generatedPart(FilingPartRole::OPIS, $position++, $opisPath, $relativeDir, null, $written, []);
        }
        $parts[] = $this->parts->copiedPart(FilingPartRole::POWER_OF_ATTORNEY, $position++, $poaPdf, $relativeDir, $written, [$selection->powerOfAttorney->getId()]);

        $firstEmailBytes = array_sum(array_map(static fn (FilingPackagePart $p): int => $p->getUnsignedBytes(), $parts));
        [$plan, $normalized] = $this->annexBundle->planWithCompression($firstEmailBytes, $normalized, $budget);
        [$plan, $volumeParts] = $this->annexBundle->buildVolumesWithinBudget($case, $plan, $normalized, $ranges, $relativeDir, $position, $written, $firstEmailBytes, $budget);

        $package = new FilingPackage($case, $version, $placement, $user);
        $package->setSource($fingerprint, $manifest);
        $package->setIncludesStampDutyProof($selection->stampDutyProofAnnexed);
        foreach ([...$parts, ...$volumeParts] as $part) {
            $package->addPart($part);
        }
        $package->setSizePlan($this->sizePlanData(
            $plan,
            $package,
            $ranges,
            $this->petitionWarnings->warnings($petitionPath, $selection->stampDutyProofAnnexed),
            array_map(static fn (Document $d): string => $d->getOriginalFilename(), [...$selection->skippedOptional, ...$selection->excludedPowersOfAttorney]),
        ));

        return $package;
    }

    private function relativeDir(LegalCase $case, int $version): string
    {
        return sprintf('cases/%d/filing/v%d/unsigned', $case->getId(), $version);
    }

    /**
     * @param array<int, AnnexRange> $ranges
     * @param list<string>           $warnings
     * @param list<string>           $skipped
     *
     * @return array<string, mixed>
     */
    private function sizePlanData(FilingSizePlan $plan, FilingPackage $package, array $ranges, array $warnings, array $skipped): array
    {
        $emails = [[]];
        foreach ($package->getParts() as $part) {
            $email = $part->getRole() === FilingPartRole::ANNEX_BUNDLE ? $plan->emailForVolume((int) $part->getVolume()) : 1;
            $emails[$email - 1][] = $part->getPartToken();
        }

        $emailBytes = [];
        foreach ($emails as $i => $tokens) {
            $bytes = 0;
            foreach ($package->getParts() as $part) {
                if (in_array($part->getPartToken(), $tokens, true)) {
                    $bytes += $part->getSignedBytes() ?? $part->getUnsignedBytes();
                }
            }
            $emailBytes[$i] = ['parts' => $tokens, 'bytes' => $bytes, 'encodedBytes' => (int) ceil($bytes * FilingSizePlanner::ENCODING_FACTOR)];
        }

        return [
            'budget' => $plan->budget,
            'emails' => $emailBytes,
            'annexRanges' => $this->annexBundle->rangesForStorage($ranges),
            'warnings' => $warnings,
            'skippedDocuments' => $skipped,
        ];
    }

    private function auditBuilt(FilingPackage $package, string $action): void
    {
        $this->auditLogService->log(
            action: $action,
            entityType: LegalCase::class,
            entityId: (string) $package->getLegalCase()->getId(),
            newData: [
                'version' => $package->getVersion(),
                'opisPlacement' => $package->getOpisPlacement()->value,
                'includesStampDutyProof' => $package->includesStampDutyProof(),
                'emails' => $package->getEmailCount(),
                'parts' => array_map(static fn (FilingPackagePart $p): array => [
                    'role' => $p->getRole()->value,
                    'volume' => $p->getVolume(),
                    'pages' => $p->getPageCount(),
                    'bytes' => $p->getUnsignedBytes(),
                    'compressed' => $p->isCompressionApplied(),
                    'signed' => $p->getSignatureStatus() !== SignatureStatus::MISSING,
                ], $package->getParts()->toArray()),
                'warnings' => $package->getWarnings(),
            ],
            category: AuditLogService::CATEGORY_FILING_PACKAGE,
        );
    }
}
