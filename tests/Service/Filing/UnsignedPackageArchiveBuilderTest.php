<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\FilingPackage;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingPackageStatus;
use App\Service\Filing\UnsignedArchiveUnavailableException;
use App\Service\Filing\UnsignedPackageArchiveBuilder;
use App\Tests\Support\FilingCaseFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UnsignedPackageArchiveBuilderTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;

    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private FilingPackage $package;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'filing-unsigned-zip');
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 3);
        $package = $this->em->getRepository(FilingPackage::class)->findOneBy(['legalCase' => $this->case]);
        self::assertInstanceOf(FilingPackage::class, $package);
        $this->package = $package;
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?', [LegalCase::class, (string) $this->case->getId()]);
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testTheArchiveHoldsEveryUnsignedFileOfThePackageUnderItsDownloadName(): void
    {
        $this->package->setStatus(FilingPackageStatus::BUILT);
        $this->em->flush();

        $path = $this->builder()->build($this->package, $this->user);

        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $names = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            $first = $this->package->getParts()->first();
            $bytes = $zip->getFromName($names[0]);
            $zip->close();

            self::assertCount($this->package->getParts()->count(), $names);
            foreach ($names as $name) {
                self::assertStringEndsWith('_v1_nesemnat.pdf', preg_replace('/_vol\d+/', '', $name));
            }
            self::assertContains('Anexe_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $this->case->getCaseNumber()) . '_v1_vol1_nesemnat.pdf', $names);
            self::assertSame($first->getUnsignedSha256(), hash('sha256', (string) $bytes));
        } finally {
            @unlink($path);
        }

        $this->em->refresh($this->package);
        self::assertSame(FilingPackageStatus::AWAITING_SIGNATURES, $this->package->getStatus());
        $audit = $this->em->getConnection()->fetchAssociative("SELECT user_id, new_data FROM audit_log WHERE action = 'filing_package_unsigned_archive_downloaded' AND entity_id = ?", [(string) $this->case->getId()]);
        self::assertIsArray($audit);
        self::assertSame($this->user->getId(), (int) $audit['user_id']);
        $data = json_decode((string) $audit['new_data'], true);
        self::assertSame(1, $data['version']);
        self::assertCount($this->package->getParts()->count(), $data['parts']);
        self::assertEqualsCanonicalizing(['role', 'volume', 'sha256'], array_keys($data['parts'][0]));
    }

    public function testAMissingFileStopsTheArchiveWithoutAuditOrStatusChange(): void
    {
        $part = $this->package->getParts()->last();
        unlink($this->filingUploadsDir . '/' . $part->getUnsignedPath());

        try {
            $this->builder()->build($this->package, $this->user);
            self::fail('A missing unsigned file must stop the archive.');
        } catch (UnsignedArchiveUnavailableException) {
        }

        self::assertSame(FilingPackageStatus::SIGNED, $this->package->getStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'filing_package_unsigned_archive_downloaded' AND entity_id = ?", [(string) $this->case->getId()]));
    }

    public function testAStalePackageHasNoArchive(): void
    {
        $this->package->markStale('annexes');

        $this->expectException(UnsignedArchiveUnavailableException::class);
        $this->builder()->build($this->package, $this->user);
    }

    private function builder(): UnsignedPackageArchiveBuilder
    {
        return static::getContainer()->get(UnsignedPackageArchiveBuilder::class);
    }
}
