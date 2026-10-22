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
use App\Enum\DocumentType;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartRole;
use App\Enum\OpisPlacement;
use App\Enum\StampDutyStatus;
use App\Repository\AppSettingRepository;
use App\Service\Document\OpisGeneratorService;
use App\Service\Filing\FilingPackageBlockedException;
use App\Service\Filing\FilingPackageBuilder;
use App\Service\Filing\FilingSizePlanner;
use App\Service\Pdf\PdfTextProbe;
use App\Service\Pdf\PdfToolException;
use App\Service\Pdf\ProcessSandbox;
use App\Service\Pdf\QpdfRunner;
use App\Tests\Support\FilingCaseFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FilingPackageBuilderTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;

    private EntityManagerInterface $em;
    private FilingPackageBuilder $builder;
    private User $user;
    private LegalCase $case;
    /** @var array<string, Document> */
    private array $docs;

    protected function setUp(): void
    {
        if (!ProcessSandbox::binariesAvailable(['qpdf', 'gs', 'magick', 'pdftotext', 'pdfinfo'])) {
            self::markTestSkipped('qpdf, gs, magick and poppler are required.');
        }

        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->builder = static::getContainer()->get(FilingPackageBuilder::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'filing-builder');
        $this->case = $this->createFilingCase($this->em, $this->user);
        $this->docs = $this->attachFilingDocuments($this->em, $this->case, $this->user);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->settings()->set(AppSetting::KEY_FILING_OPIS_PLACEMENT, null);
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testMergedPackageHasPetitionWithOpisAnnexesAndPowerOfAttorney(): void
    {
        $package = $this->builder->build($this->case, $this->user);

        self::assertSame(OpisPlacement::MERGED, $package->getOpisPlacement());
        self::assertSame(
            [FilingPartRole::PETITION, FilingPartRole::POWER_OF_ATTORNEY, FilingPartRole::ANNEX_BUNDLE],
            array_map(static fn (FilingPackagePart $p): FilingPartRole => $p->getRole(), $package->getParts()->toArray()),
        );
        self::assertSame(FilingPackageStatus::BUILT, $package->getStatus());
        self::assertSame(1, $package->getEmailCount());

        // The petition's own two pages come first and unchanged, the index after them.
        $petitionPart = $package->partsWithRole(FilingPartRole::PETITION)[0];
        $pages = $this->text()->pageTexts($this->abs($petitionPart));
        $originalPages = $this->text()->pageTexts($this->abs($this->docs['petition']));
        self::assertSame($originalPages, array_slice($pages, 0, 2));
        self::assertStringContainsString('OPIS DE DOCUMENTE', implode(' ', array_slice($pages, 2)));
        self::assertSame($petitionPart->getPartToken(), $this->qpdf()->readInfoKey($this->abs($petitionPart), QpdfRunner::PART_ID_KEY));

        // Power of attorney copied byte for byte.
        $poaPart = $package->partsWithRole(FilingPartRole::POWER_OF_ATTORNEY)[0];
        self::assertSame(hash_file('sha256', $this->abs($this->docs['poa'])), hash_file('sha256', $this->abs($poaPart)));
    }

    public function testSeparatePlacementAddsAnOpisPartAndCopiesThePetitionByteForByte(): void
    {
        $this->settings()->set(AppSetting::KEY_FILING_OPIS_PLACEMENT, 'separate');

        $package = $this->builder->build($this->case, $this->user);

        self::assertSame(OpisPlacement::SEPARATE, $package->getOpisPlacement());
        self::assertCount(1, $package->partsWithRole(FilingPartRole::OPIS));
        $petitionPart = $package->partsWithRole(FilingPartRole::PETITION)[0];
        $petitionSha = hash_file('sha256', $this->abs($this->docs['petition']));
        self::assertSame($petitionSha, $petitionPart->getUnsignedSha256());
        self::assertSame($petitionSha, hash_file('sha256', $this->abs($petitionPart)));
    }

    public function testBuildLeavesTheCasePetitionAndOpisDocumentsUntouched(): void
    {
        $opis = $this->attachPdf($this->em, $this->case, $this->user, DocumentType::OPIS, 'Opis.pdf', ['Opis vechi']);
        $before = [];
        foreach ([$this->docs['petition'], $opis] as $document) {
            $before[$document->getId()] = [hash_file('sha256', $this->abs($document)), $document->getFileSize()];
        }

        $this->builder->build($this->case, $this->user);
        $this->em->clear();

        foreach ($before as $id => [$sha, $size]) {
            $document = $this->em->find(Document::class, $id);
            self::assertSame($sha, hash_file('sha256', $this->abs($document)));
            self::assertSame($size, $document->getFileSize());
        }
    }

    public function testAnnexOrderFollowsTheIndexAndTheOpisRangesMatchTheBundle(): void
    {
        $package = $this->builder->build($this->case, $this->user);

        $expected = [];
        foreach (static::getContainer()->get(OpisGeneratorService::class)->orderedDocuments($this->case) as $document) {
            if (!in_array($document->getDocumentType(), [DocumentType::IMPUTERNICIRE_AVOCATIALA, DocumentType::ORDONANTA_PLATA], true)) {
                $expected[] = $document->getId();
            }
        }
        $bundle = $package->partsWithRole(FilingPartRole::ANNEX_BUNDLE)[0];
        self::assertSame($expected, $bundle->getSourceDocumentIds());

        // somatie 2 + communication 1 + stamp proof 1 + contract 3 + invoice image 1.
        $ranges = $package->getSizePlan()['annexRanges'];
        self::assertSame([1, 2], $ranges[(string) $this->docs['somatie']->getId()]);
        self::assertSame([3, 3], $ranges[(string) $this->docs['communication']->getId()]);
        self::assertSame([4, 4], $ranges[(string) $this->docs['stamp']->getId()]);
        self::assertSame([5, 7], $ranges[(string) $this->docs['contract']->getId()]);
        self::assertSame([8, 8], $ranges[(string) $this->docs['invoice']->getId()]);
        self::assertSame(1, $bundle->getAnnexPageFrom());
        self::assertSame(8, $bundle->getAnnexPageTo());

        // Cover + 8 numbered pages; the cover is outside the numbering.
        $pages = $this->text()->pageTexts($this->abs($bundle));
        self::assertCount(9, $pages);
        self::assertStringContainsString('ANEXE', $pages[0]);
        self::assertStringContainsString('Paginile 1-8', $pages[0]);
        self::assertStringContainsString('Contract pagina 1', $pages[5]);
        self::assertMatchesRegularExpression('/(^|\s)5(\s|$)/', $pages[5]);

        $opisText = PdfTextProbe::normalize(implode(' ', array_slice($this->text()->pageTexts($this->abs($package->partsWithRole(FilingPartRole::PETITION)[0])), 2)));
        self::assertStringContainsString('pag. 5-7', $opisText);
        self::assertStringContainsString('pag. 8', $opisText);
        self::assertStringContainsString('Anexele, în copie, paginile 1-8', $opisText);
    }

    public function testStampProofIsIncludedWhenPaidWithProof(): void
    {
        $package = $this->builder->build($this->case, $this->user);

        self::assertTrue($package->includesStampDutyProof());
        self::assertContains($this->docs['stamp']->getId(), $package->partsWithRole(FilingPartRole::ANNEX_BUNDLE)[0]->getSourceDocumentIds());
    }

    public function testStampProofIsExcludedWhenStampingIsDeferred(): void
    {
        $this->case->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
        $this->em->flush();

        $package = $this->builder->build($this->case, $this->user);

        self::assertFalse($package->includesStampDutyProof());
        self::assertNotContains($this->docs['stamp']->getId(), $package->partsWithRole(FilingPartRole::ANNEX_BUNDLE)[0]->getSourceDocumentIds());
        $opisText = implode(' ', array_slice($this->text()->pageTexts($this->abs($package->partsWithRole(FilingPartRole::PETITION)[0])), 2));
        self::assertStringNotContainsString('Dovada_taxa.pdf', $opisText);
        // The fixture petition still says "conform dovezii anexate": the card warns.
        self::assertContains('petition_stamp_wording_mismatch', $package->getWarnings());
    }

    public function testIssuedOrderAndPowerOfAttorneyNeverEnterTheAnnexBundle(): void
    {
        $package = $this->builder->build($this->case, $this->user);

        $ids = $package->partsWithRole(FilingPartRole::ANNEX_BUNDLE)[0]->getSourceDocumentIds();
        self::assertNotContains($this->docs['order']->getId(), $ids);
        self::assertNotContains($this->docs['poa']->getId(), $ids);
        self::assertNotContains($this->docs['petition']->getId(), $ids);
    }

    public function testPackageOpisDoesNotMentionVolumesOrEmails(): void
    {
        $package = $this->builder->build($this->case, $this->user);

        $opisText = mb_strtolower(implode(' ', array_slice($this->text()->pageTexts($this->abs($package->partsWithRole(FilingPartRole::PETITION)[0])), 2)));
        self::assertStringNotContainsString('volum', $opisText);
        self::assertStringNotContainsString('e-mail', $opisText);
        self::assertStringNotContainsString('original', $opisText);
    }

    public function testImageAnnexIsConvertedToAPdfPage(): void
    {
        $package = $this->builder->build($this->case, $this->user);

        $ranges = $package->getSizePlan()['annexRanges'];
        self::assertSame([8, 8], $ranges[(string) $this->docs['invoice']->getId()]);
        self::assertSame(9, $this->qpdf()->pageCount($this->abs($package->partsWithRole(FilingPartRole::ANNEX_BUNDLE)[0])));
    }

    public function testEncryptedAnnexIsRefusedWithTheDocumentName(): void
    {
        $this->attachBytes($this->em, $this->case, $this->user, DocumentType::ANEXA, 'Extras_protejat.pdf', self::encryptPdf(self::pdfBytes(['Secret'])), 'application/pdf');

        try {
            $this->builder->build($this->case, $this->user);
            self::fail('An encrypted annex must stop the build.');
        } catch (PdfToolException $e) {
            self::assertSame('filing.package.error.encrypted', $e->messageKey);
            self::assertSame('Extras_protejat.pdf', $e->messageParams['%document%']);
        }
        self::assertSame(0, $this->packageCount());
    }

    public function testMissingProofOfServiceBlocksTheBuild(): void
    {
        $this->case->getDocuments()->removeElement($this->docs['communication']);
        $this->em->remove($this->docs['communication']);
        $this->em->flush();

        try {
            $this->builder->build($this->case, $this->user);
            self::fail('The proof of service gate must block.');
        } catch (FilingPackageBlockedException $e) {
            self::assertSame('case_overview.payment_order.flash_error_communication_proof_missing', $e->messageKey);
        }
        self::assertSame(0, $this->packageCount());
    }

    public function testLawyerWithoutANameBlocksTheBuild(): void
    {
        $this->user->setFirstName(null);
        $this->user->setLastName(null);
        $this->em->flush();

        try {
            $this->builder->build($this->case, $this->user);
            self::fail('A nameless cover statement must not be built.');
        } catch (FilingPackageBlockedException $e) {
            self::assertSame('filing.package.error.lawyer_name_missing', $e->messageKey);
        }
        self::assertSame(0, $this->packageCount());
    }

    public function testUnchangedSourcesReturnTheSamePackage(): void
    {
        $first = $this->builder->build($this->case, $this->user);
        $second = $this->builder->build($this->case, $this->user);

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(1, $this->packageCount());
    }

    public function testNewBuildSupersedesThePreviousOneAndKeepsItsFiles(): void
    {
        $first = $this->builder->build($this->case, $this->user);
        $firstFiles = array_map(fn (FilingPackagePart $p): string => $this->abs($p), $first->getParts()->toArray());

        $this->attachPdf($this->em, $this->case, $this->user, DocumentType::ANEXA, 'Anexa_noua.pdf', ['Anexa noua']);
        $second = $this->builder->build($this->case, $this->user);

        self::assertSame(2, $second->getVersion());
        self::assertSame(FilingPackageStatus::SUPERSEDED, $first->getStatus());
        self::assertSame($first->getId(), $second->getPreviousVersion()?->getId());
        foreach ($firstFiles as $file) {
            self::assertFileExists($file);
        }
        $audit = $this->em->getRepository(AuditLog::class)->findBy(['action' => 'filing_package_built', 'entityId' => (string) $this->case->getId()]);
        self::assertCount(2, $audit);
        self::assertSame('FILING_PACKAGE', $audit[0]->getCategory());
    }

    public function testRebuildWithASmallBudgetSplitsVolumesWithCoversAndContinuousNumberingKeepingThePetitionAndPowerOfAttorney(): void
    {
        $package = $this->builder->build($this->case, $this->user);
        $petition = $package->partsWithRole(FilingPartRole::PETITION)[0];
        $poa = $package->partsWithRole(FilingPartRole::POWER_OF_ATTORNEY)[0];

        // A budget that holds email 1 and any single annex with its volume overhead,
        // but not all the annexes together: the split is forced, not optional.
        $firstEmail = $petition->getUnsignedBytes() + $poa->getUnsignedBytes();
        $annexSizes = array_map(fn (string $key): int => (int) filesize($this->abs($this->docs[$key])), ['somatie', 'communication', 'stamp', 'contract', 'invoice']);
        $largest = max($annexSizes);
        $rawBudget = max($firstEmail, FilingSizePlanner::DEFAULT_VOLUME_OVERHEAD + 3 * FilingSizePlanner::DEFAULT_PAGE_OVERHEAD + 2 * $largest);
        self::assertGreaterThan($rawBudget, FilingSizePlanner::DEFAULT_VOLUME_OVERHEAD + 8 * FilingSizePlanner::DEFAULT_PAGE_OVERHEAD + array_sum($annexSizes), 'Precondition: the annexes must not fit in one volume.');
        $maxEmailBytes = (int) ceil(($rawBudget + static::getContainer()->getParameter('lexrecovery.filing.signature_reserve_bytes')) * FilingSizePlanner::ENCODING_FACTOR);

        $rebuilt = $this->builder->rebuildAnnexVolumes($package, $maxEmailBytes);

        self::assertSame(FilingPackageStatus::SUPERSEDED, $package->getStatus());
        self::assertSame($package->getId(), $rebuilt->getPreviousVersion()?->getId());
        self::assertSame($petition->getUnsignedPath(), $rebuilt->partsWithRole(FilingPartRole::PETITION)[0]->getUnsignedPath());
        self::assertSame($poa->getUnsignedPath(), $rebuilt->partsWithRole(FilingPartRole::POWER_OF_ATTORNEY)[0]->getUnsignedPath());

        $volumes = $rebuilt->partsWithRole(FilingPartRole::ANNEX_BUNDLE);
        self::assertGreaterThanOrEqual(2, count($volumes));
        $expectedFrom = 1;
        foreach ($volumes as $volume) {
            self::assertSame(count($volumes), $volume->getVolumeCount());
            self::assertSame($expectedFrom, $volume->getAnnexPageFrom());
            $pages = $this->text()->pageTexts($this->abs($volume));
            self::assertStringContainsString('ANEXE', $pages[0], 'Each volume starts with its cover.');
            self::assertCount($volume->getAnnexPageTo() - $volume->getAnnexPageFrom() + 2, $pages);
            self::assertMatchesRegularExpression('/(^|\s)' . $expectedFrom . '(\s|$)/', $pages[1]);
            $expectedFrom = $volume->getAnnexPageTo() + 1;
        }
        self::assertSame(9, $expectedFrom);
        self::assertGreaterThan(1, $rebuilt->getEmailCount());
    }

    public function testRebuildRefusesWhenAnnexPagesWouldMove(): void
    {
        $package = $this->builder->build($this->case, $this->user);
        file_put_contents($this->abs($this->docs['communication']), self::pdfBytes(['Confirmare', 'Pagina adaugata']));

        $this->expectException(FilingPackageBlockedException::class);
        $this->expectExceptionMessage('filing.package.error.rebuild_ranges_changed');

        $this->builder->rebuildAnnexVolumes($package, 9_500_000);
    }

    private function abs(Document|FilingPackagePart $item): string
    {
        return $this->filingUploadsDir . '/' . ($item instanceof Document ? $item->getStoredFilename() : $item->getUnsignedPath());
    }

    private function packageCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM filing_package WHERE legal_case_id = ?', [$this->case->getId()]);
    }

    private function settings(): AppSettingRepository
    {
        return static::getContainer()->get(AppSettingRepository::class);
    }

    private function text(): PdfTextProbe
    {
        return static::getContainer()->get(PdfTextProbe::class);
    }

    private function qpdf(): QpdfRunner
    {
        return static::getContainer()->get(QpdfRunner::class);
    }
}
