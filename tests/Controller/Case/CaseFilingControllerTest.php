<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\AuditLog;
use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\FilingPackageStatus;
use App\Service\Filing\FilingPackageBuilder;
use App\Service\Pdf\ProcessSandbox;
use App\Tests\Support\FilingCaseFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CaseFilingControllerTest extends WebTestCase
{
    use FilingCaseFixtureTrait;

    private const TURBO = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    /** @var list<User> */
    private array $otherUsers = [];

    protected function setUp(): void
    {
        if (!ProcessSandbox::binariesAvailable(['qpdf', 'gs', 'magick', 'pdftotext', 'pdfinfo'])) {
            self::markTestSkipped('qpdf, gs, magick and poppler are required.');
        }

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'filing-ctrl');
        $this->case = $this->createFilingCase($this->em, $this->user);
        $this->attachFilingDocuments($this->em, $this->case, $this->user);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
            foreach ($this->otherUsers as $other) {
                $this->removeFilingFixtures($this->em, $other, null);
            }
        }
        parent::tearDown();
    }

    public function testBuildRefusesAnInvalidCsrfTokenWithATurboStreamError(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->buildUrl(), ['_token' => 'forged'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString($this->trans('filing.package.flash.csrf'), (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->packageCount());
    }

    public function testAnotherLawyerCannotBuildOrDownload(): void
    {
        $package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $other = $this->createFilingUser($this->em, 'filing-other');
        $this->em->flush();
        $this->otherUsers[] = $other;
        $this->client->loginUser($other);

        $this->client->request('POST', $this->buildUrl(), ['_token' => 'irrelevant'], [], self::TURBO);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', $this->partUrl($package, $package->getParts()->first()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCannotDownloadAFileButStillOpensTheCase(): void
    {
        $package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $admin = $this->createFilingUser($this->em, 'filing-admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->otherUsers[] = $admin;
        $this->client->loginUser($admin);

        $this->client->request('GET', $this->partUrl($package, $package->getParts()->first()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#filing-package-card');
        self::assertSelectorNotExists('#filing-package-card a[href*="/filing/package/"]');
    }

    public function testBuildOnACaseWithoutAGeneratedPetitionAnswersWithTheWrongStatusError(): void
    {
        $this->client->loginUser($this->user);
        // The card hides the form outside CERERE_GENERATA: take the token first, then
        // move the case back, the state a stale tab or a replayed POST would hit.
        $token = $this->tokenFromCard();
        $this->em->getConnection()->executeStatement('UPDATE legal_case SET status = ? WHERE id = ?', [CaseStatus::SOMATIE_TRIMISA->value, $this->case->getId()]);

        $this->client->request('POST', $this->buildUrl(), ['_token' => $token], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('filing.package.blocked_wrong_status'), (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->packageCount());
    }

    public function testTwoBuildsWithUnchangedSourcesLeaveOnePackage(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->tokenFromCard();

        $this->client->request('POST', $this->buildUrl(), ['_token' => $token], [], self::TURBO);
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="filing-package-card"', $content);
        self::assertStringContainsString($this->trans('filing.package.flash.built'), $content);

        $this->client->request('POST', $this->buildUrl(), ['_token' => $token], [], self::TURBO);
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->packageCount());
    }

    public function testDownloadServesThePdfAuditsItAndMovesThePackageToAwaitingSignatures(): void
    {
        $package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $part = $package->getParts()->first();
        self::assertInstanceOf(FilingPackagePart::class, $part);
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->partUrl($package, $part));

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('nesemnat.pdf', (string) $response->headers->get('Content-Disposition'));

        $this->em->clear();
        self::assertSame(FilingPackageStatus::AWAITING_SIGNATURES, $this->em->find(FilingPackage::class, $package->getId())?->getStatus());
        $audit = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'filing_package_part_downloaded', 'entityId' => (string) $this->case->getId()]);
        self::assertNotNull($audit);
        self::assertSame('FILING_PACKAGE', $audit->getCategory());
        self::assertSame($this->user->getId(), $audit->getUser()?->getId());
    }

    public function testDownloadOfAPartFromAnotherPackageIsNotFound(): void
    {
        $package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $part = $package->getParts()->first();
        $this->client->loginUser($this->user);

        $this->client->request('GET', sprintf('/case/%d/filing/package/%d/part/%d', $this->case->getId(), $package->getId() + 1000, $part->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    private function buildUrl(): string
    {
        return '/case/' . $this->case->getId() . '/filing/package/build';
    }

    private function partUrl(FilingPackage $package, FilingPackagePart $part): string
    {
        return sprintf('/case/%d/filing/package/%d/part/%d', $this->case->getId(), $package->getId(), $part->getId());
    }

    private function tokenFromCard(): string
    {
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('#filing-package-card form[action$="/filing/package/build"] input[name="_token"]')->attr('value');
    }

    private function packageCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM filing_package WHERE legal_case_id = ?', [$this->case->getId()]);
    }

    private function trans(string $key): string
    {
        return htmlspecialchars(static::getContainer()->get('translator')->trans($key), ENT_QUOTES);
    }
}
