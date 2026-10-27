<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\FilingPackage;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingPackageStatus;
use App\Tests\Support\FilingCaseFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CaseFilingUnsignedZipTest extends WebTestCase
{
    use FilingCaseFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private FilingPackage $package;
    /** @var list<User> */
    private array $otherUsers = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('app.uploads_dir');
        $this->user = $this->createFilingUser($this->em, 'filing-zip-ctrl');
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $package = $this->em->getRepository(FilingPackage::class)->findOneBy(['legalCase' => $this->case]);
        self::assertInstanceOf(FilingPackage::class, $package);
        $this->package = $package;
        $this->package->setStatus(FilingPackageStatus::BUILT);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?', [LegalCase::class, (string) $this->case->getId()]);
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
            foreach ($this->otherUsers as $other) {
                $this->removeFilingFixtures($this->em, $other, null);
            }
        }
        parent::tearDown();
    }

    public function testTheLawyerDownloadsEveryUnsignedFileInOneAuditedArchive(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->zipUrl($this->package));

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertStringContainsString('_v1_nesemnat.zip', (string) $response->headers->get('Content-Disposition'));

        $tmp = (string) tempnam(sys_get_temp_dir(), 'zip-test');
        file_put_contents($tmp, $this->client->getInternalResponse()->getContent());
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($tmp));
            self::assertSame($this->package->getParts()->count(), $zip->numFiles);
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                self::assertStringEndsWith('_nesemnat.pdf', (string) $zip->getNameIndex($i));
            }
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        $this->em->clear();
        self::assertSame(FilingPackageStatus::AWAITING_SIGNATURES, $this->em->find(FilingPackage::class, $this->package->getId())?->getStatus());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'filing_package_unsigned_archive_downloaded' AND category = 'FILING_PACKAGE' AND entity_id = ? AND user_id = ?",
            [(string) $this->case->getId(), $this->user->getId()],
        ));
    }

    public function testAnAdminAndAnotherLawyerAreRefused(): void
    {
        $admin = $this->createFilingUser($this->em, 'filing-zip-admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $other = $this->createFilingUser($this->em, 'filing-zip-other');
        $this->em->flush();
        $this->otherUsers[] = $admin;
        $this->otherUsers[] = $other;

        foreach ([$admin, $other] as $viewer) {
            $this->client->loginUser($viewer);
            $this->client->request('GET', $this->zipUrl($this->package));
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'filing_package_unsigned_archive_downloaded' AND entity_id = ?", [(string) $this->case->getId()]));
    }

    public function testAStaleOrAnotherPackageIsNotFound(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', sprintf('/case/%d/filing/package/%d/unsigned.zip', $this->case->getId(), $this->package->getId() + 1000));
        self::assertResponseStatusCodeSame(404);

        $this->package->markStale('annexes');
        $this->em->flush();
        $this->client->request('GET', $this->zipUrl($this->package));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheTabOffersTheArchiveToTheLawyerOnly(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', sprintf('/case/%d?tab=depunere', $this->case->getId()));
        self::assertSelectorExists(sprintf('#filing-parts a[data-testid="filing-unsigned-zip"][href="%s"]', $this->zipUrl($this->package)));

        $admin = $this->createFilingUser($this->em, 'filing-zip-admin-view');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->otherUsers[] = $admin;
        $this->client->loginUser($admin);
        $this->client->request('GET', sprintf('/case/%d?tab=depunere', $this->case->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#depunere-status');
        self::assertSelectorNotExists('[data-testid="filing-unsigned-zip"]');
        self::assertSelectorNotExists('#filing-package-card a[href*="/filing/package/"]');
    }

    private function zipUrl(FilingPackage $package): string
    {
        return sprintf('/case/%d/filing/package/%d/unsigned.zip', $this->case->getId(), $package->getId());
    }
}
