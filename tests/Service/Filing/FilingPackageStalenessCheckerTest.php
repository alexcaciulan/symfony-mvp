<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\FilingPackageStatus;
use App\Service\Filing\FilingPackageBuilder;
use App\Service\Filing\FilingPackageStalenessChecker;
use App\Service\Pdf\ProcessSandbox;
use App\Tests\Support\FilingCaseFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FilingPackageStalenessCheckerTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;

    private EntityManagerInterface $em;
    private FilingPackageStalenessChecker $checker;
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
        $this->checker = static::getContainer()->get(FilingPackageStalenessChecker::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'filing-stale');
        $this->case = $this->createFilingCase($this->em, $this->user);
        $this->docs = $this->attachFilingDocuments($this->em, $this->case, $this->user);
        static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testUnchangedSourcesKeepThePackageBuilt(): void
    {
        $package = $this->checker->refresh($this->case);

        self::assertSame(FilingPackageStatus::BUILT, $package?->getStatus());
        self::assertNull($package->getStaleReason());
    }

    public function testChangingThePowerOfAttorneyDateMarksThePackageStale(): void
    {
        $this->docs['poa']->setPowerOfAttorneyDate(new \DateTimeImmutable('2026-01-20'));
        $this->em->flush();

        $package = $this->checker->refresh($this->case);

        self::assertSame(FilingPackageStatus::STALE, $package?->getStatus());
        self::assertSame('power_of_attorney', $package->getStaleReason());
        $this->em->clear();
        self::assertSame('STALE', $this->em->getConnection()->fetchOne('SELECT status FROM filing_package WHERE id = ?', [$package->getId()]));
    }

    public function testANewAnnexMarksThePackageStale(): void
    {
        $this->attachPdf($this->em, $this->case, $this->user, DocumentType::ANEXA, 'Anexa_noua.pdf', ['Anexa noua']);

        $package = $this->checker->refresh($this->case);

        self::assertSame(FilingPackageStatus::STALE, $package?->getStatus());
        self::assertSame('annexes', $package->getStaleReason());
    }

    public function testASentPackageStaysSentWhenItsSourcesChange(): void
    {
        $package = $this->checker->refresh($this->case);
        $package->setStatus(FilingPackageStatus::SENT);
        $this->em->flush();

        $this->docs['poa']->setPowerOfAttorneyDate(new \DateTimeImmutable('2026-01-20'));
        $this->attachPdf($this->em, $this->case, $this->user, DocumentType::ANEXA, 'Anexa_noua.pdf', ['Anexa noua']);

        self::assertSame(FilingPackageStatus::SENT, $this->checker->refresh($this->case)?->getStatus());
    }
}
