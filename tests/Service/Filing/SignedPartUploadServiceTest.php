<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\AppSetting;
use App\Entity\AuditLog;
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
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
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
    }

    /** The same visible signature from a tool that rewrites the file: a probable match to confirm. */
    public function testARewrittenFileWithAVisibleSignatureOverTheTextIsAProbableMatch(): void
    {
        $this->signAllExcept(FilingPartRole::PETITION);
        $part = $this->part(FilingPartRole::PETITION);

        $this->service()->upload($part, $this->uploaded($this->signed($part, rewrite: true, visibleRect: [34, 752, 300, 790])), $this->user, false);

        self::assertSame(SignatureStatus::PRESENT_PROBABLE, $part->getSignatureStatus());
        self::assertTrue($part->isAwaitingProbableConfirmation());
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
        }

        self::assertSame(SignatureStatus::MISSING, $annex->getSignatureStatus());
        self::assertSame([], $this->signedFiles());
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
