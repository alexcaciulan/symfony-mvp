<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\AppSetting;
use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartRole;
use App\Enum\SignatureStatus;
use App\Repository\AppSettingRepository;
use App\Service\AuditLogService;
use App\Service\Filing\FilingPackageBuilder;
use App\Service\Filing\FilingLockQuery;
use App\Service\Filing\FilingPackageStalenessChecker;
use App\Service\Filing\FilingPartWriter;
use App\Service\Filing\FilingSizePlanner;
use App\Service\Filing\SignedPartMatch;
use App\Service\Filing\SignedPartMatcher;
use App\Service\Filing\SignedPartOverBudgetException;
use App\Service\Filing\SignedPartUploadService;
use App\Service\Filing\SignedUploadRefusedException;
use App\Service\Pdf\PdfSignatureInspector;
use App\Service\Pdf\PdfTextProbe;
use App\Service\Pdf\ProcessSandbox;
use App\Service\Pdf\QpdfRunner;
use App\Service\Pdf\UploadedPdfValidator;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\TestPdfSigner;
use App\Tests\Support\TestPdfVariants;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

final class SignedPartUploadServiceTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;

    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private FilingPackage $package;
    private string $scratch;

    protected function setUp(): void
    {
        if (!ProcessSandbox::binariesAvailable(['qpdf', 'gs', 'magick', 'pdftotext', 'pdfinfo'])) {
            self::markTestSkipped('qpdf, gs, magick and poppler are required.');
        }

        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('app.uploads_dir');
        $this->user = $this->createFilingUser($this->em, 'filing-signed');
        $this->case = $this->createFilingCase($this->em, $this->user);
        $this->attachFilingDocuments($this->em, $this->case, $this->user);
        $this->package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $this->scratch = sys_get_temp_dir() . '/signed-upload-' . bin2hex(random_bytes(4));
        mkdir($this->scratch);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            static::getContainer()->get(AppSettingRepository::class)->set(AppSetting::KEY_FILING_OPIS_PLACEMENT, null);
            if (isset($this->case)) {
                $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?', [LegalCase::class, (string) $this->case->getId()]);
            }
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
            foreach (glob(($this->scratch ?? '/nonexistent') . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->scratch ?? '');
        }
        parent::tearDown();
    }

    public function testIncrementallySignedPetitionIsMatchedStoredAndCounted(): void
    {
        $part = $this->part(FilingPartRole::PETITION);
        $signed = $this->signed($part);

        $this->service()->upload($part, $this->uploaded($signed), $this->user, false);

        self::assertSame(SignatureStatus::PRESENT_MATCHED, $part->getSignatureStatus());
        self::assertTrue($part->isSignatureAccepted());
        self::assertStringContainsString('/filing/v1/signed/', (string) $part->getSignedPath());
        self::assertSame(hash_file('sha256', $signed), hash_file('sha256', $this->filingUploadsDir . '/' . $part->getSignedPath()));
        self::assertSame(filesize($signed), $part->getSignedBytes());
        self::assertSame('prefix', $part->getSignatureReport()['matchMethod']);
        self::assertSame('Test Avocat', $part->getSignatureReport()['signerCn']);
        // The account is "Ana Avocat": the certificate's name differs, which is only a warning.
        self::assertSame(['filing.signature.warning.cn_differs'], $part->getSignatureWarnings());
        self::assertSame([], $part->getSignatureMatchNotes());

        $this->em->clear();
        $package = $this->em->find(FilingPackage::class, $this->package->getId());
        self::assertSame(filesize($signed), $package->getTotalSignedBytes());
        $email = $package->getSizePlan()['emails'][0];
        $expected = 0;
        foreach ($package->getParts() as $each) {
            $expected += $each->getSignedBytes() ?? $each->getUnsignedBytes();
        }
        self::assertSame($expected, $email['bytes']);
        self::assertSame((int) ceil($expected * FilingSizePlanner::ENCODING_FACTOR), $email['encodedBytes']);
        self::assertSame(FilingPackageStatus::AWAITING_SIGNATURES, $package->getStatus());

        $audit = $this->audit('filing_signed_part_uploaded');
        self::assertCount(1, $audit);
        $data = $audit[0]->getNewData();
        self::assertSame('PRESENT_MATCHED', $data['status']);
        self::assertSame(hash_file('sha256', $signed), $data['sha256']);
        self::assertArrayNotHasKey('signerCn', $data);
    }

    public function testRewrittenSignedFileIsProbableAndNeedsTheLawyersConfirmation(): void
    {
        $this->signAllExcept(FilingPartRole::PETITION);
        $part = $this->part(FilingPartRole::PETITION);
        $signed = $this->signed($part, rewrite: true);

        $this->service()->upload($part, $this->uploaded($signed), $this->user, false);

        self::assertSame(SignatureStatus::PRESENT_PROBABLE, $part->getSignatureStatus());
        self::assertFalse($part->isSignatureAccepted());
        self::assertTrue($part->isAwaitingProbableConfirmation());
        self::assertSame('text', $part->getSignatureReport()['matchMethod']);
        self::assertSame([], $part->getSignatureMatchNotes());
        self::assertSame(FilingPackageStatus::AWAITING_SIGNATURES, $part->getPackage()->getStatus());

        try {
            $this->service()->confirmProbable($part, $this->user, str_repeat('0', 64));
            self::fail('A confirmation for another file must be refused.');
        } catch (SignedUploadRefusedException $e) {
            self::assertSame('filing.upload.error.concurrent', $e->messageKey);
        }
        self::assertFalse($part->isSignatureAccepted());

        $this->service()->confirmProbable($part, $this->user, (string) $part->getSignedSha256());

        self::assertTrue($part->isSignatureAccepted());
        self::assertSame($this->user->getId(), $part->getProbableMatchConfirmedBy()?->getId());
        self::assertSame(FilingPackageStatus::SIGNED, $part->getPackage()->getStatus());
        self::assertCount(1, $this->audit('filing_signed_part_probable_confirmed'));
    }

    /** A visible signature drawn over the text interleaves its words with the page words. */
    public function testAVisibleSignatureOverTheTextIsStillMatched(): void
    {
        $this->signAllExcept(FilingPartRole::PETITION);
        $part = $this->part(FilingPartRole::PETITION);

        $this->service()->upload($part, $this->uploaded($this->signed($part, visibleRect: [34, 752, 300, 790])), $this->user, false);

        self::assertSame(SignatureStatus::PRESENT_MATCHED, $part->getSignatureStatus());
        self::assertTrue($part->isSignatureAccepted());
        self::assertSame([], $part->getSignatureMatchNotes());
    }

    /** The same visible signature from a tool that rewrites the file: a probable match to confirm. */
    public function testARewrittenFileWithAVisibleSignatureOverTheTextIsAProbableMatch(): void
    {
        $this->signAllExcept(FilingPartRole::PETITION);
        $part = $this->part(FilingPartRole::PETITION);

        $this->service()->upload($part, $this->uploaded($this->signed($part, rewrite: true, visibleRect: [34, 752, 300, 790])), $this->user, false);

        self::assertSame(SignatureStatus::PRESENT_PROBABLE, $part->getSignatureStatus());
        self::assertTrue($part->isAwaitingProbableConfirmation());
        self::assertSame([], $part->getSignatureMatchNotes());
    }

    public function testAVisibleSignatureOnTheLastPageIsMatched(): void
    {
        $part = $this->part(FilingPartRole::PETITION);

        $this->upload($part, $this->signedVariant($part, null, ['page' => -1, 'visibleRect' => [34, 60, 300, 100]]));

        self::assertSame(SignatureStatus::PRESENT_MATCHED, $part->getSignatureStatus());
        self::assertSame([], $part->getSignatureMatchNotes());
    }

    /**
     * Tools that linearize or compress the file before signing keep its text and
     * its part token, but not its bytes.
     */
    public function testLinearizedOrCompressedInputSignedAfterwardsIsProbableWithoutNotes(): void
    {
        foreach (['linearize', 'objectStreams'] as $variant) {
            $part = $this->part(FilingPartRole::PETITION);

            $this->upload($part, $this->signedVariant($part, TestPdfVariants::$variant(...)), replace: $part->hasSignedFile());

            self::assertSame(SignatureStatus::PRESENT_PROBABLE, $part->getSignatureStatus(), $variant);
            self::assertSame('text', $part->getSignatureReport()['matchMethod'], $variant);
            self::assertSame([], $part->getSignatureMatchNotes(), $variant);
        }
    }

    /** Ghostscript re-distils the pages and keeps only the standard Info keys. */
    public function testARedistilledOrPdfAFileIsProbableWithTheMissingTokenNoted(): void
    {
        foreach (['gsPdfwrite', 'gsPdfA'] as $variant) {
            $part = $this->part(FilingPartRole::PETITION);

            $this->upload($part, $this->signedVariant($part, TestPdfVariants::$variant(...)), replace: $part->hasSignedFile());

            self::assertSame(SignatureStatus::PRESENT_PROBABLE, $part->getSignatureStatus(), $variant);
            self::assertSame([['key' => 'filing.signature.note.part_id_missing', 'params' => []]], $part->getSignatureMatchNotes(), $variant);
        }
    }

    public function testAPartTokenKeptOnlyInTheXmpMetadataCountsAsPresent(): void
    {
        foreach ([false, true] as $asAttribute) {
            $part = $this->part(FilingPartRole::PETITION);
            $token = (string) static::getContainer()->get(QpdfRunner::class)->readInfoKey($this->abs($part), QpdfRunner::PART_ID_KEY);
            $file = $this->signedVariant($part, static function (string $in, string $out) use ($token, $asAttribute): void {
                TestPdfVariants::removeInfo($in, $out . '.noinfo.pdf');
                TestPdfVariants::withXmpToken($out . '.noinfo.pdf', $out, $token, $asAttribute);
                @unlink($out . '.noinfo.pdf');
            });

            $this->upload($part, $file, replace: $part->hasSignedFile());

            self::assertSame(SignatureStatus::PRESENT_PROBABLE, $part->getSignatureStatus());
            self::assertSame([], $part->getSignatureMatchNotes());
        }
    }

    public function testAFileWithoutInfoAndMetadataIsProbableWithTheMissingTokenNoted(): void
    {
        $part = $this->part(FilingPartRole::PETITION);

        $this->upload($part, $this->signedVariant($part, TestPdfVariants::removeInfoAndMetadata(...)));

        self::assertSame(SignatureStatus::PRESENT_PROBABLE, $part->getSignatureStatus());
        self::assertSame([['key' => 'filing.signature.note.part_id_missing', 'params' => []]], $part->getSignatureMatchNotes());
    }

    public function testTextDrawnIntoThePageIsProbableWithTheAddedTextNoted(): void
    {
        $part = $this->part(FilingPartRole::PETITION);

        $this->upload($part, $this->signedVariant($part, static fn (string $in, string $out) => TestPdfVariants::stampIntoContent($in, $out, 'Semnat electronic de TEST SIGNER')));

        self::assertSame(SignatureStatus::PRESENT_PROBABLE, $part->getSignatureStatus());
        self::assertSame([['key' => 'filing.signature.note.text_added', 'params' => ['%page%' => 1, '%count%' => 5]]], $part->getSignatureMatchNotes());
    }

    public function testAddedTextWithDigitsGetsTheStrongerNote(): void
    {
        $part = $this->part(FilingPartRole::PETITION);

        $this->upload($part, $this->signedVariant($part, static fn (string $in, string $out) => TestPdfVariants::stampIntoContent($in, $out, 'Semnat la 2026-10-25 12:00', 2)));

        self::assertSame([
            ['key' => 'filing.signature.note.text_added', 'params' => ['%page%' => 2, '%count%' => 4]],
            ['key' => 'filing.signature.note.text_added_with_digits', 'params' => ['%page%' => 2]],
        ], $part->getSignatureMatchNotes());
    }

    public function testAFreeTextCommentIsProbableWithTheAddedTextNoted(): void
    {
        $part = $this->part(FilingPartRole::PETITION);

        $this->upload($part, $this->signedVariant($part, static fn (string $in, string $out) => TestPdfSigner::addFreeText($in, $out, 'Vazut avocat', [40, 60, 200, 80])));

        self::assertSame(SignatureStatus::PRESENT_PROBABLE, $part->getSignatureStatus());
        self::assertSame('prefix', $part->getSignatureReport()['matchMethod']);
        self::assertSame([['key' => 'filing.signature.note.text_added', 'params' => ['%page%' => 1, '%count%' => 2]]], $part->getSignatureMatchNotes());
    }

    public function testASignaturePageAppendedIncrementallyIsProbableWithTheAppendedPageNoted(): void
    {
        $part = $this->part(FilingPartRole::PETITION);

        $this->upload($part, $this->signedVariant($part, static fn (string $in, string $out) => TestPdfSigner::appendPage($in, $out, 'Pagina de semnaturi'), ['page' => -1]));

        self::assertSame(SignatureStatus::PRESENT_PROBABLE, $part->getSignatureStatus());
        self::assertSame('prefix', $part->getSignatureReport()['matchMethod']);
        self::assertSame([['key' => 'filing.signature.note.pages_appended', 'params' => ['%count%' => 1]]], $part->getSignatureMatchNotes());
    }

    public function testASignaturePageAppendedByARewriteIsProbableWithTheAppendedPageNoted(): void
    {
        $part = $this->part(FilingPartRole::PETITION);

        $this->upload($part, $this->signedVariant($part, TestPdfVariants::appendPageRewrite(...)));

        self::assertSame(SignatureStatus::PRESENT_PROBABLE, $part->getSignatureStatus());
        self::assertSame([['key' => 'filing.signature.note.pages_appended', 'params' => ['%count%' => 1]]], $part->getSignatureMatchNotes());
    }

    public function testTwoSignaturesATimestampAndValidationDataKeepTheMatch(): void
    {
        $part = $this->part(FilingPartRole::PETITION);
        $once = $this->signed($part);
        $twice = $this->scratch . '/twice.pdf';
        TestPdfSigner::sign($once, $twice);
        $timestamped = $this->scratch . '/timestamped.pdf';
        TestPdfSigner::timestamp($once, $timestamped);
        $withDss = $this->scratch . '/timestamped-dss.pdf';
        $this->appendDss($timestamped, $withDss);

        foreach (['two signatures' => $twice, 'timestamp' => $timestamped, 'timestamp and DSS' => $withDss] as $label => $file) {
            $this->upload($part, $file, replace: $part->hasSignedFile());

            self::assertSame(SignatureStatus::PRESENT_MATCHED, $part->getSignatureStatus(), $label);
            self::assertSame([], $part->getSignatureMatchNotes(), $label);
        }
        self::assertSame(2, $part->getSignatureReport()['signatureCount']);
        self::assertContains('filing.signature.warning.timestamp_appended', $part->getSignatureWarnings());
        self::assertContains('filing.signature.warning.trailing_update', $part->getSignatureWarnings());
    }

    public function testAChangedAmountIsAMismatch(): void
    {
        $part = $this->part(FilingPartRole::PETITION);

        $this->assertRefusedWith('filing.signature.refused.mismatch', SignatureStatus::MISMATCH, $part, $this->signedVariant(
            $part,
            static fn (string $in, string $out) => TestPdfVariants::changeText($in, $out, '200,00', '100,00'),
        ));
    }

    /** Added words never stand in for deleted ones: every generated word has to be there. */
    public function testADeletedLineIsAMismatchEvenWithTextAddedOnTheSamePage(): void
    {
        $part = $this->part(FilingPartRole::PETITION);

        $this->assertRefusedWith('filing.signature.refused.mismatch', SignatureStatus::MISMATCH, $part, $this->signedVariant(
            $part,
            static function (string $in, string $out): void {
                TestPdfVariants::deleteLineContaining($in, $out . '.deleted.pdf', 'CERERE');
                TestPdfVariants::stampIntoContent($out . '.deleted.pdf', $out, 'CERERE DE ORDONANTA DE PLATA');
                @unlink($out . '.deleted.pdf');
            },
        ));
    }

    public function testReversedOrMissingAnnexPagesAreAMismatch(): void
    {
        $annex = $this->part(FilingPartRole::ANNEX_BUNDLE);
        self::assertGreaterThan(2, $annex->getPageCount());

        $this->assertRefusedWith('filing.signature.refused.mismatch', SignatureStatus::MISMATCH, $annex, $this->signedVariant($annex, TestPdfVariants::reversePages(...)));
        $this->assertRefusedWith('filing.signature.refused.mismatch', SignatureStatus::MISMATCH, $annex, $this->signedVariant(
            $annex,
            static fn (string $in, string $out) => TestPdfVariants::dropPage($in, $out, 2),
        ));
        $this->assertRefusedWith('filing.signature.refused.mismatch', SignatureStatus::MISMATCH, $annex, $this->signedVariant(
            $annex,
            static fn (string $in, string $out) => TestPdfVariants::dropPage($in, $out, $annex->getPageCount()),
        ));
    }

    public function testAForeignTokenInTheXmpMetadataIsAMismatch(): void
    {
        $part = $this->part(FilingPartRole::PETITION);

        $this->assertRefusedWith('filing.signature.refused.mismatch', SignatureStatus::MISMATCH, $part, $this->signedVariant(
            $part,
            static function (string $in, string $out): void {
                TestPdfVariants::removeInfo($in, $out . '.noinfo.pdf');
                TestPdfVariants::withXmpToken($out . '.noinfo.pdf', $out, str_repeat('cd', 16));
                @unlink($out . '.noinfo.pdf');
            },
        ));
    }

    /**
     * A page without text is compared only by position: without the token the
     * text cannot identify the part. The builder numbers every annex page, so the
     * tokened part with a scanned page is made here and matched directly.
     */
    public function testAMissingTokenOnATokenedPartWithATextlessPageIsAMismatch(): void
    {
        $relative = 'cases/' . $this->case->getId() . '/filing/matcher/anexe-scan.pdf';
        $absolute = $this->filingUploadsDir . '/' . $relative;
        mkdir(dirname($absolute), 0o777, true);
        $plain = $this->scratch . '/scan.pdf';
        file_put_contents($plain, self::pdfBytes(['Anexa 1', '']));
        $token = str_repeat('ef', 16);
        $sandbox = static::getContainer()->get(ProcessSandbox::class);
        $sandbox->begin();
        try {
            static::getContainer()->get(QpdfRunner::class)->setInfoKey($plain, $absolute, QpdfRunner::PART_ID_KEY, $token);
        } finally {
            $sandbox->end();
        }
        $part = new FilingPackagePart(FilingPartRole::ANNEX_BUNDLE, 9, 'Anexe', 2, $relative, (string) hash_file('sha256', $absolute), (int) filesize($absolute), $token, []);

        self::assertSame(SignatureStatus::MISMATCH, $this->match($part, $this->signedVariant($part, TestPdfVariants::removeInfoAndMetadata(...)))->status);

        $kept = $this->match($part, $this->signedVariant($part, TestPdfVariants::qpdfRewrite(...)));
        self::assertSame(SignatureStatus::PRESENT_PROBABLE, $kept->status);
        self::assertSame(['filing.signature.warning.textless_pages'], $kept->warnings);
        self::assertSame([], $kept->notes);
    }

    /** A copied part (here a scanned power of attorney) never carried a token, so none can be missing. */
    public function testACopiedTextlessPartWithoutInfoIsProbableWithoutAMissingTokenNote(): void
    {
        $this->attachPdf($this->em, $this->case, $this->user, DocumentType::IMPUTERNICIRE_AVOCATIALA, 'Imputernicire_scan.pdf', [''], static function (Document $d): void {
            $d->setPowerOfAttorneySeries('B');
            $d->setPowerOfAttorneyNumber('5678');
            $d->setPowerOfAttorneyDate(new \DateTimeImmutable('2026-02-01'));
        });
        $this->package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $poa = $this->part(FilingPartRole::POWER_OF_ATTORNEY);

        $this->upload($poa, $this->signedVariant($poa, TestPdfVariants::removeInfoAndMetadata(...)));

        self::assertSame(SignatureStatus::PRESENT_PROBABLE, $poa->getSignatureStatus());
        self::assertSame(['filing.signature.warning.textless_pages'], array_values(array_diff($poa->getSignatureWarnings(), ['filing.signature.warning.cn_differs'])));
        self::assertSame([], $poa->getSignatureMatchNotes());
    }

    public function testTheNotesReachTheReportAndBothAuditsWithoutAnyExtractedText(): void
    {
        $this->signAllExcept(FilingPartRole::PETITION);
        $part = $this->part(FilingPartRole::PETITION);
        $this->upload($part, $this->signedVariant($part, static function (string $in, string $out): void {
            TestPdfVariants::removeInfoAndMetadata($in, $out . '.bare.pdf');
            TestPdfVariants::stampIntoContent($out . '.bare.pdf', $out, 'Semnat de Ionescu Maria 2026');
            @unlink($out . '.bare.pdf');
        }));
        $expected = [
            ['key' => 'filing.signature.note.text_added', 'params' => ['%page%' => 1, '%count%' => 5]],
            ['key' => 'filing.signature.note.text_added_with_digits', 'params' => ['%page%' => 1]],
            ['key' => 'filing.signature.note.part_id_missing', 'params' => []],
        ];
        self::assertSame($expected, $part->getSignatureReport()['matchNotes']);

        $this->service()->confirmProbable($part, $this->user, (string) $part->getSignedSha256());

        $uploads = $this->audit('filing_signed_part_uploaded');
        $upload = $uploads[array_key_last($uploads)]->getNewData();
        $confirmation = $this->audit('filing_signed_part_probable_confirmed')[0]->getNewData();
        self::assertSame($expected, $upload['matchNotes']);
        self::assertSame($expected, $confirmation['matchNotes']);
        self::assertSame($part->getSignatureWarnings(), $confirmation['warnings']);
        foreach ([$upload, $confirmation] as $data) {
            self::assertArrayNotHasKey('signerCn', $data);
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString('Ionescu', $json);
            self::assertStringNotContainsString('Semnat', $json);
        }
    }

    public function testAnAppendedPageThatPushesTheEmailOverItsLimitTakesTheOverBudgetPath(): void
    {
        $unsignedTotal = 0;
        foreach ($this->package->getParts() as $each) {
            $unsignedTotal += $each->getUnsignedBytes();
        }
        $service = $this->service((int) ceil($unsignedTotal * FilingSizePlanner::ENCODING_FACTOR) + 1000);
        $annex = $this->part(FilingPartRole::ANNEX_BUNDLE);

        try {
            $service->upload($annex, $this->uploaded($this->signedVariant($annex, TestPdfVariants::appendPageRewrite(...))), $this->user, false);
            self::fail('Expected an over-budget refusal.');
        } catch (SignedPartOverBudgetException $e) {
            self::assertTrue($e->rebuildable);
        }
        self::assertSame(SignatureStatus::MISSING, $annex->getSignatureStatus());
        self::assertSame([], $this->signedFiles());
    }

    public function testSignedFileOfAnotherPartIsAMismatchAndLeavesThePartUntouched(): void
    {
        $part = $this->part(FilingPartRole::PETITION);
        $other = $this->signed($this->part(FilingPartRole::POWER_OF_ATTORNEY));

        $this->assertRefusedWith('filing.signature.refused.mismatch', SignatureStatus::MISMATCH, $part, $other);

        self::assertSame(SignatureStatus::MISSING, $part->getSignatureStatus());
        self::assertNull($part->getSignedPath());
        self::assertSame([], $this->signedFiles());
        $refusals = $this->audit('filing_signed_upload_refused');
        self::assertCount(1, $refusals);
        self::assertSame('MISMATCH', $refusals[0]->getNewData()['status']);
    }

    public function testATokenedFileSignedFromAnotherGeneratedPartIsAMismatchEvenWithTheSamePagesAndText(): void
    {
        // A rewritten copy keeping the text and pages but carrying another part's token.
        $part = $this->part(FilingPartRole::PETITION);
        $foreign = $this->scratch . '/foreign.pdf';
        static::getContainer()->get(ProcessSandbox::class)->begin();
        try {
            static::getContainer()->get(QpdfRunner::class)->setInfoKey($this->abs($part), $foreign, QpdfRunner::PART_ID_KEY, str_repeat('ab', 16));
        } finally {
            static::getContainer()->get(ProcessSandbox::class)->end();
        }
        $signed = $this->scratch . '/foreign-signed.pdf';
        TestPdfSigner::sign($foreign, $signed);

        $this->assertRefusedWith('filing.signature.refused.mismatch', SignatureStatus::MISMATCH, $part, $signed);
    }

    public function testUnsignedFileIsRefusedAsNotSigned(): void
    {
        $part = $this->part(FilingPartRole::PETITION);
        $copy = $this->scratch . '/unsigned.pdf';
        copy($this->abs($part), $copy);

        $this->assertRefusedWith('filing.signature.refused.not_signed', SignatureStatus::NOT_SIGNED, $part, $copy);
    }

    public function testAlteredSignedFileIsRefusedAsDamaged(): void
    {
        $part = $this->part(FilingPartRole::PETITION);
        $signed = $this->signed($part);
        $bytes = (string) file_get_contents($signed);
        $bytes[7] = $bytes[7] === '4' ? '5' : '4';
        file_put_contents($signed, $bytes);

        $this->assertRefusedWith('filing.signature.refused.damaged', SignatureStatus::DAMAGED, $part, $signed);
    }

    public function testARefusalAfterAnAcceptedSignatureKeepsTheEarlierFile(): void
    {
        $part = $this->part(FilingPartRole::PETITION);
        $this->service()->upload($part, $this->uploaded($this->signed($part)), $this->user, false);
        $acceptedSha = $part->getSignedSha256();
        $copy = $this->scratch . '/unsigned.pdf';
        copy($this->abs($part), $copy);

        $this->assertRefusedWith('filing.signature.refused.not_signed', SignatureStatus::NOT_SIGNED, $part, $copy, replace: true);

        $this->em->clear();
        $reloaded = $this->em->find(FilingPackagePart::class, $part->getId());
        self::assertSame($acceptedSha, $reloaded->getSignedSha256());
        self::assertSame(SignatureStatus::PRESENT_MATCHED, $reloaded->getSignatureStatus());
        self::assertTrue($reloaded->isSignatureAccepted());
        self::assertSame('filing.signature.refused.not_signed', $reloaded->getLastRefusalKey());
    }

    public function testFileRefusalsStayOnThePartUntilTheNextUploadAndAreAudited(): void
    {
        $part = $this->part(FilingPartRole::PETITION);
        $unsigned = $this->scratch . '/unsigned.pdf';
        copy($this->abs($part), $unsigned);
        $notPdf = $this->scratch . '/note.pdf';
        file_put_contents($notPdf, 'plain text, not a PDF');
        $foreign = $this->signed($this->part(FilingPartRole::POWER_OF_ATTORNEY));

        foreach ([
            'filing.signature.refused.not_signed' => [$unsigned, SignatureStatus::NOT_SIGNED],
            'filing.upload.error.not_pdf' => [$notPdf, null],
            'filing.signature.refused.mismatch' => [$foreign, SignatureStatus::MISMATCH],
        ] as $key => [$file, $status]) {
            $this->assertRefusedWith($key, $status, $part, $file);
            $this->em->clear();
            $reloaded = $this->em->find(FilingPackagePart::class, $part->getId());
            self::assertSame($key, $reloaded->getLastRefusalKey());
            self::assertSame([], $reloaded->getLastRefusalParams());
            self::assertNotNull($reloaded->getLastRefusalAt());
            self::assertSame(SignatureStatus::MISSING, $reloaded->getSignatureStatus());
            $part = $reloaded;
        }
        self::assertSame(
            ['filing.signature.refused.not_signed', 'filing.upload.error.not_pdf', 'filing.signature.refused.mismatch'],
            array_map(static fn (AuditLog $a): string => $a->getNewData()['reason'], $this->audit('filing_signed_upload_refused')),
        );

        $this->user = $this->em->find(User::class, $this->user->getId());
        $this->service()->upload($part, $this->uploaded($this->signed($part)), $this->user, false);
        $this->em->clear();
        $accepted = $this->em->find(FilingPackagePart::class, $part->getId());
        self::assertNull($accepted->getLastRefusalKey());
        self::assertNull($accepted->getLastRefusalAt());
    }

    public function testAToolFailureWhileInspectingIsKeptOnThePartAndAudited(): void
    {
        $part = $this->part(FilingPartRole::PETITION);
        $signed = $this->signed($part);
        // The generated file can no longer be read, so the comparison fails in the PDF tools.
        file_put_contents($this->abs($part), "%PDF-1.7\nnot really a PDF");

        try {
            $this->upload($part, $signed);
            self::fail('Expected a refusal.');
        } catch (SignedUploadRefusedException $e) {
            self::assertContains($e->messageKey, ['filing.upload.error.unreadable', 'filing.upload.error.timeout']);
            $key = $e->messageKey;
        }

        $this->em->clear();
        self::assertSame($key, $this->em->find(FilingPackagePart::class, $part->getId())->getLastRefusalKey());
        self::assertSame($key, $this->audit('filing_signed_upload_refused')[0]->getNewData()['reason']);
    }

    public function testStateAndConcurrencyRefusalsDescribeNoFileAndLeaveNoReasonOnThePart(): void
    {
        $part = $this->part(FilingPartRole::PETITION);
        $signed = $this->signed($part);
        $this->upload($part, $signed);

        $this->assertRefusedWith('filing.upload.error.already_signed', null, $part, $signed);

        $this->package->setStatus(FilingPackageStatus::SENT);
        $this->em->flush();
        $this->assertRefusedWith('filing.upload.error.not_uploadable', null, $part, $signed, replace: true);
        $this->package->setStatus(FilingPackageStatus::SIGNED);
        $this->em->flush();

        $poa = $this->part(FilingPartRole::POWER_OF_ATTORNEY);
        $poaSigned = $this->signed($poa);
        $this->em->getConnection()->executeStatement(
            "UPDATE filing_package_part SET signed_sha256 = ?, signed_path = 'cases/x/other.pdf', signed_bytes = 1, signature_status = 'PRESENT_MATCHED' WHERE id = ?",
            [str_repeat('c', 64), $poa->getId()],
        );
        $this->assertRefusedWith('filing.upload.error.concurrent', null, $poa, $poaSigned);

        $this->em->clear();
        foreach ([$part->getId(), $poa->getId()] as $id) {
            self::assertNull($this->em->find(FilingPackagePart::class, $id)->getLastRefusalKey());
        }
        self::assertSame([], $this->audit('filing_signed_upload_refused'));
    }

    public function testAnEmptiedOversizedPostIsAuditedAndKeptOnThePart(): void
    {
        $part = $this->part(FilingPartRole::ANNEX_BUNDLE);

        $this->service()->recordTooLarge($part);

        $this->em->clear();
        self::assertSame('filing.upload.error.too_large', $this->em->find(FilingPackagePart::class, $part->getId())->getLastRefusalKey());
        $refusals = $this->audit('filing_signed_upload_refused');
        self::assertCount(1, $refusals);
        self::assertSame('filing.upload.error.too_large', $refusals[0]->getNewData()['reason']);
        self::assertNull($refusals[0]->getNewData()['sha256']);
    }

    public function testEveryPartSignedMakesThePackageSignedAndAReplaceOrAnUnconfirmedMatchMovesItBack(): void
    {
        $this->signAllExcept(null);
        self::assertSame(FilingPackageStatus::SIGNED, $this->package->getStatus());

        $petition = $this->part(FilingPartRole::PETITION);
        try {
            $this->service()->upload($petition, $this->uploaded($this->signed($petition)), $this->user, false);
            self::fail('A second upload without replace must be refused.');
        } catch (SignedUploadRefusedException $e) {
            self::assertSame('filing.upload.error.already_signed', $e->messageKey);
        }

        $this->service()->upload($petition, $this->uploaded($this->signed($petition, rewrite: true)), $this->user, true);

        self::assertSame(FilingPackageStatus::AWAITING_SIGNATURES, $this->package->getStatus());
        $uploads = $this->audit('filing_signed_part_uploaded');
        self::assertCount($this->package->getParts()->count() + 1, $uploads);
        self::assertTrue($uploads[array_key_last($uploads)]->getNewData()['replaced']);
        self::assertSame('PRESENT_PROBABLE', $uploads[array_key_last($uploads)]->getNewData()['status']);
    }

    public function testSignedAnnexOverTheEmailLimitProposesARebuildAndStoresNothing(): void
    {
        $unsignedTotal = 0;
        foreach ($this->package->getParts() as $part) {
            $unsignedTotal += $part->getUnsignedBytes();
        }
        $service = $this->service((int) ceil($unsignedTotal * FilingSizePlanner::ENCODING_FACTOR) + 1000);
        $annex = $this->part(FilingPartRole::ANNEX_BUNDLE);

        try {
            $service->upload($annex, $this->uploaded($this->signed($annex)), $this->user, false);
            self::fail('Expected an over-budget refusal.');
        } catch (SignedPartOverBudgetException $e) {
            self::assertTrue($e->rebuildable);
            self::assertGreaterThan(0, $e->excessBytes);
            $excess = $e->excessBytes;
        }

        self::assertSame(SignatureStatus::MISSING, $annex->getSignatureStatus());
        self::assertSame([], $this->signedFiles());
        // The row keeps what its rebuild proposal needs, nothing about the file itself.
        $this->em->clear();
        $reloaded = $this->em->find(FilingPackagePart::class, $annex->getId());
        self::assertSame('filing.upload.over_budget.text', $reloaded->getLastRefusalKey());
        self::assertSame(['excessBytes' => $excess, 'rebuildable' => true], $reloaded->getLastRefusalParams());
        self::assertSame('filing.upload.over_budget.text', $this->audit('filing_signed_upload_refused')[0]->getNewData()['reason']);
    }

    public function testOversizedPetitionInAnEmailWithoutAnnexesCannotBeRebuilt(): void
    {
        $petition = $this->part(FilingPartRole::PETITION);
        $poa = $this->part(FilingPartRole::POWER_OF_ATTORNEY);
        $annex = $this->part(FilingPartRole::ANNEX_BUNDLE);
        $plan = $this->package->getSizePlan();
        $plan['emails'] = FilingSizePlanner::emailBytes($this->package->getParts(), [[$petition->getPartToken(), $poa->getPartToken()], [$annex->getPartToken()]]);
        $this->package->setSizePlan($plan);
        $this->em->flush();
        $service = $this->service((int) ceil(($petition->getUnsignedBytes() + $poa->getUnsignedBytes()) * FilingSizePlanner::ENCODING_FACTOR) + 1000);

        try {
            $service->upload($petition, $this->uploaded($this->signed($petition)), $this->user, false);
            self::fail('Expected an over-budget refusal.');
        } catch (SignedPartOverBudgetException $e) {
            self::assertFalse($e->rebuildable);
            self::assertSame('filing.upload.error.first_email_too_large', $e->messageKey());
        }
        self::assertSame([], $this->signedFiles());
        $this->em->clear();
        $reloaded = $this->em->find(FilingPackagePart::class, $petition->getId());
        self::assertSame('filing.upload.error.first_email_too_large', $reloaded->getLastRefusalKey());
        self::assertFalse($reloaded->getLastRefusalParams()['rebuildable']);
    }

    public function testStaleSupersededOrSentPackagesAndAFiledCaseAreRefused(): void
    {
        $part = $this->part(FilingPartRole::PETITION);
        $signed = $this->signed($part);

        $this->package->setStatus(FilingPackageStatus::SENT);
        $this->em->flush();
        $this->assertRefusedWith('filing.upload.error.not_uploadable', null, $part, $signed);

        $this->package->setStatus(FilingPackageStatus::AWAITING_SIGNATURES);
        $this->case->setStatus(CaseStatus::CERERE_DEPUSA);
        $this->em->flush();
        $this->assertRefusedWith('filing.upload.error.not_uploadable', null, $part, $signed);

        $this->case->setStatus(CaseStatus::CERERE_GENERATA);
        $this->package->markStale('annexes');
        $this->em->flush();
        $this->assertRefusedWith('filing.upload.error.not_uploadable', null, $part, $signed);

        // A new build supersedes the stale package; its parts no longer take uploads.
        static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        self::assertSame(FilingPackageStatus::SUPERSEDED, $this->package->getStatus());
        $this->assertRefusedWith('filing.upload.error.not_uploadable', null, $part, $signed);
        self::assertSame([], $this->signedFiles());
    }

    public function testAnUploadThatFinishedInBetweenWinsAndTheLateOneIsRefusedWithoutLeavingAFile(): void
    {
        $part = $this->part(FilingPartRole::PETITION);
        $signed = $this->signed($part);
        // Another request stored its signed file after this one read the part.
        $this->em->getConnection()->executeStatement(
            "UPDATE filing_package_part SET signed_sha256 = ?, signed_path = 'cases/x/other.pdf', signed_bytes = 1, signature_status = 'PRESENT_MATCHED' WHERE id = ?",
            [str_repeat('c', 64), $part->getId()],
        );

        $this->assertRefusedWith('filing.upload.error.concurrent', null, $part, $signed);

        self::assertSame([], $this->signedFiles());
        self::assertSame(str_repeat('c', 64), $this->em->getConnection()->fetchOne('SELECT signed_sha256 FROM filing_package_part WHERE id = ?', [$part->getId()]));
    }

    public function testSeparateOpisPartIsSignedLikeTheOthers(): void
    {
        static::getContainer()->get(AppSettingRepository::class)->set(AppSetting::KEY_FILING_OPIS_PLACEMENT, 'separate');
        $this->attachPdf($this->em, $this->case, $this->user, DocumentType::ANEXA, 'Anexa_extra.pdf', ['Anexa extra']);
        $this->package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        self::assertCount(4, $this->package->getParts());

        $this->signAllExcept(FilingPartRole::OPIS);
        self::assertSame(FilingPackageStatus::AWAITING_SIGNATURES, $this->package->getStatus());

        $opis = $this->part(FilingPartRole::OPIS);
        $this->service()->upload($opis, $this->uploaded($this->signed($opis)), $this->user, false);

        self::assertSame(FilingPackageStatus::SIGNED, $this->package->getStatus());
    }

    private function assertRefusedWith(string $key, ?SignatureStatus $status, FilingPackagePart $part, string $file, bool $replace = false): void
    {
        try {
            $this->service()->upload($part, $this->uploaded($file), $this->user, $replace);
            self::fail('Expected a refusal with ' . $key);
        } catch (SignedUploadRefusedException $e) {
            self::assertSame($key, $e->messageKey);
            self::assertSame($status, $e->status);
        }
    }

    private function signAllExcept(?FilingPartRole $role): void
    {
        foreach ($this->package->getParts() as $part) {
            if ($part->getRole() !== $role) {
                $this->service()->upload($part, $this->uploaded($this->signed($part)), $this->user, false);
            }
        }
    }

    private function part(FilingPartRole $role): FilingPackagePart
    {
        return $this->package->partsWithRole($role)[0];
    }

    /**
     * @param list<int>|null $visibleRect
     */
    private function signed(FilingPackagePart $part, bool $rewrite = false, ?array $visibleRect = null): string
    {
        $source = $this->abs($part);
        if ($rewrite) {
            $rewritten = $this->scratch . '/' . bin2hex(random_bytes(4)) . '-rewritten.pdf';
            (new Process(['qpdf', '--object-streams=disable', $source, $rewritten]))->mustRun();
            $source = $rewritten;
        }
        $out = $this->scratch . '/' . bin2hex(random_bytes(4)) . '-signed.pdf';
        TestPdfSigner::sign($source, $out, $visibleRect !== null ? ['visibleRect' => $visibleRect] : []);

        return $out;
    }

    /**
     * The generated part passed through a tool, then signed.
     *
     * @param (callable(string, string): void)|null $transform
     * @param array<string, mixed>                  $signOptions
     */
    private function signedVariant(FilingPackagePart $part, ?callable $transform, array $signOptions = []): string
    {
        $source = $this->abs($part);
        if ($transform !== null) {
            $variant = $this->scratch . '/' . bin2hex(random_bytes(4)) . '-variant.pdf';
            $transform($source, $variant);
            $source = $variant;
        }
        $out = $this->scratch . '/' . bin2hex(random_bytes(4)) . '-signed.pdf';
        TestPdfSigner::sign($source, $out, $signOptions);

        return $out;
    }

    private function match(FilingPackagePart $part, string $signed): SignedPartMatch
    {
        $container = static::getContainer();
        $sandbox = $container->get(ProcessSandbox::class);
        $matcher = new SignedPartMatcher($container->get(QpdfRunner::class), $container->get(PdfTextProbe::class), $container->get(FilingPartWriter::class), $sandbox);
        $sandbox->begin();
        try {
            return $matcher->match($part, $signed, static::getContainer()->get(QpdfRunner::class)->pageCount($signed));
        } finally {
            $sandbox->end();
        }
    }

    private function upload(FilingPackagePart $part, string $file, bool $replace = false): void
    {
        $this->service()->upload($part, $this->uploaded($file), $this->user, $replace);
    }

    /** Long-term validation data appended after the last signature, as Adobe and DSS do. */
    private function appendDss(string $in, string $out): void
    {
        $certDer = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', (string) file_get_contents(TestPdfSigner::FIXTURE_DIR . '/test-signer.crt')), true);
        TestPdfSigner::appendCatalogEntry($in, $out, '/DSS', '{1} 0 R', [
            '<< /Type /DSS /Certs [{2} 0 R] >>',
            '<< /Length ' . strlen((string) $certDer) . " >>\nstream\n" . $certDer . "\nendstream",
        ]);
    }

    private function uploaded(string $path): UploadedFile
    {
        $copy = $this->scratch . '/' . bin2hex(random_bytes(4)) . '-upload';
        copy($path, $copy);

        return new UploadedFile($copy, 'semnat.pdf', 'application/pdf', null, true);
    }

    private function abs(FilingPackagePart $part): string
    {
        return $this->filingUploadsDir . '/' . $part->getUnsignedPath();
    }

    /** @return list<string> */
    private function signedFiles(): array
    {
        return glob($this->filingUploadsDir . '/cases/' . $this->case->getId() . '/filing/v*/signed/*') ?: [];
    }

    /** @return list<AuditLog> */
    private function audit(string $action): array
    {
        return $this->em->getRepository(AuditLog::class)->findBy(['action' => $action, 'entityId' => (string) $this->case->getId()], ['id' => 'ASC']);
    }

    private function service(?int $maxEmailBytes = null): SignedPartUploadService
    {
        $container = static::getContainer();
        if ($maxEmailBytes === null) {
            return $container->get(SignedPartUploadService::class);
        }

        $sandbox = $container->get(ProcessSandbox::class);
        $qpdf = $container->get(QpdfRunner::class);
        $writer = $container->get(FilingPartWriter::class);

        return new SignedPartUploadService(
            $this->em,
            $container->get(FilingPackageStalenessChecker::class),
            new UploadedPdfValidator($sandbox, $qpdf),
            new PdfSignatureInspector($sandbox, $qpdf),
            new SignedPartMatcher($qpdf, $container->get(PdfTextProbe::class), $writer, $sandbox),
            $writer,
            $sandbox,
            $container->get(AuditLogService::class),
            $maxEmailBytes,
            $container->get(FilingLockQuery::class),
        );
    }
}
