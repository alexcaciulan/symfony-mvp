<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\AppSetting;
use App\Entity\AuditLog;
use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\SignatureStatus;
use App\Repository\AppSettingRepository;
use App\Service\Filing\FilingPackageBuilder;
use App\Service\Filing\SignedPartUploadService;
use App\Service\Pdf\ProcessSandbox;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\TestPdfSigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

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
            static::getContainer()->get(AppSettingRepository::class)->set(AppSetting::KEY_FILING_OPIS_PLACEMENT, null);
            if (isset($this->case)) {
                $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?', [LegalCase::class, (string) $this->case->getId()]);
            }
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

    public function testOwnerUploadsASignedPartAndTheCardShowsTheDetectedSignature(): void
    {
        $package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $part = $package->getParts()->first();
        $this->client->loginUser($this->user);
        $token = $this->uploadTokenFromCard($part);

        $this->client->request('POST', $this->partUrl($package, $part) . '/signed', ['_token' => $token], ['signed_file' => $this->signedUpload($part)], self::TURBO);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="filing-package-card"', $content);
        self::assertStringContainsString($this->trans('filing.upload.success.matched'), $content);
        self::assertStringContainsString($this->trans('filing.signature.chip.PRESENT_MATCHED'), $content);
        self::assertStringContainsString($this->trans('filing.signature.notice.validity'), $content);
        self::assertStringContainsString('rel="noopener noreferrer"', $content);

        $this->em->clear();
        self::assertSame(SignatureStatus::PRESENT_MATCHED, $this->em->find(FilingPackagePart::class, $part->getId())?->getSignatureStatus());
    }

    public function testUploadWithAnInvalidCsrfTokenIsRefusedAndStoresNothing(): void
    {
        $package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $part = $package->getParts()->first();
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->partUrl($package, $part) . '/signed', ['_token' => 'forged'], ['signed_file' => $this->signedUpload($part)], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('filing.upload.error.csrf'), (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertSame(SignatureStatus::MISSING, $this->em->find(FilingPackagePart::class, $part->getId())?->getSignatureStatus());
    }

    public function testAnEmptiedOversizedPostReadsAsTooLargeNotAsAnExpiredSession(): void
    {
        $package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->partUrl($package, $package->getParts()->first()) . '/signed', [], [], [...self::TURBO, 'CONTENT_LENGTH' => '25000000']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('filing.upload.error.too_large'), (string) $this->client->getResponse()->getContent());
    }

    public function testAdminAndAnotherLawyerCannotUploadOrDownloadASignedFile(): void
    {
        $package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $part = $package->getParts()->first();
        $this->uploadDirectly($part);
        $admin = $this->createFilingUser($this->em, 'filing-admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $other = $this->createFilingUser($this->em, 'filing-other');
        $this->em->flush();
        $this->otherUsers[] = $admin;
        $this->otherUsers[] = $other;

        foreach ([$admin, $other] as $intruder) {
            $this->client->loginUser($intruder);
            $this->client->request('GET', $this->partUrl($package, $part) . '/signed');
            self::assertResponseStatusCodeSame(403);
            $this->client->request('POST', $this->partUrl($package, $part) . '/signed', ['_token' => 'irrelevant'], ['signed_file' => $this->signedUpload($part)], self::TURBO);
            self::assertResponseStatusCodeSame(403);
            $this->client->request('POST', $this->partUrl($package, $part) . '/signed/confirm', ['_token' => 'irrelevant'], [], self::TURBO);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testSignedDownloadReturnsTheUploadedBytesToTheOwnerAndIsAudited(): void
    {
        $package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $part = $package->getParts()->first();
        $sha = $this->uploadDirectly($part);
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->partUrl($package, $part) . '/signed');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertStringContainsString('_semnat.pdf', (string) $response->headers->get('Content-Disposition'));
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame($sha, hash_file('sha256', $response->getFile()->getPathname()));
        self::assertNotNull($this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'filing_signed_part_downloaded', 'entityId' => (string) $this->case->getId()]));
    }

    public function testInSeparateModeThePackageIsSignedOnlyAfterTheFourthSignature(): void
    {
        static::getContainer()->get(AppSettingRepository::class)->set(AppSetting::KEY_FILING_OPIS_PLACEMENT, 'separate');
        $package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $parts = $package->getParts()->toArray();
        self::assertCount(4, $parts);
        $this->client->loginUser($this->user);

        foreach ($parts as $i => $part) {
            $token = $this->uploadTokenFromCard($part);
            $this->client->request('POST', $this->partUrl($package, $part) . '/signed', ['_token' => $token], ['signed_file' => $this->signedUpload($part)], self::TURBO);
            self::assertResponseIsSuccessful();
            $this->em->clear();
            $status = $this->em->find(FilingPackage::class, $package->getId())?->getStatus();
            self::assertSame($i < 3 ? FilingPackageStatus::AWAITING_SIGNATURES : FilingPackageStatus::SIGNED, $status);
        }
        self::assertStringContainsString($this->trans('filing.signature.package_signed'), (string) $this->client->getResponse()->getContent());
    }

    public function testRebuildRouteClampsTheExcessAndCreatesANewVersion(): void
    {
        $package = static::getContainer()->get(FilingPackageBuilder::class)->build($this->case, $this->user);
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_annex_rebuild_' . $package->getId());

        // An excess larger than the whole mailbox is clamped, so the volumes stay plannable.
        $this->client->request('POST', sprintf('/case/%d/filing/package/%d/rebuild-annexes', $this->case->getId(), $package->getId()), ['_token' => $token, 'excess' => '999999999'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('filing.upload.over_budget.rebuilt'), (string) $this->client->getResponse()->getContent());
        self::assertSame(2, $this->packageCount());
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

    private function uploadTokenFromCard(FilingPackagePart $part): string
    {
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertResponseIsSuccessful();
        $form = $crawler->filter(sprintf('#filing-package-card [data-testid="filing-part-%d"] form[data-testid="filing-part-upload-form"]', $part->getId()));
        self::assertCount(1, $form);
        // A text-only match is confirmed only after upload, once its warnings are shown.
        self::assertCount(0, $form->filter('input[name="probable_confirmed"]'));

        return (string) $form->filter('input[name="_token"]')->attr('value');
    }

    private function signedUpload(FilingPackagePart $part): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ctrlsig');
        TestPdfSigner::sign($this->filingUploadsDir . '/' . $part->getUnsignedPath(), $path);

        return new UploadedFile($path, 'semnat.pdf', 'application/pdf', null, true);
    }

    private function uploadDirectly(FilingPackagePart $part): string
    {
        $file = $this->signedUpload($part);
        $sha = (string) hash_file('sha256', $file->getPathname());
        static::getContainer()->get(SignedPartUploadService::class)->upload($part, $file, $this->user, false);

        return $sha;
    }

    /** A stateful CSRF token bound to the test client's session (see DeadlineAgendaAuthorizationTest). */
    private function csrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/case/' . $this->case->getId());
        $request = $this->client->getRequest();
        $stack = static::getContainer()->get(RequestStack::class);
        $stack->push($request);
        try {
            $token = static::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        } finally {
            $stack->pop();
        }
        $request->getSession()->save();

        return $token;
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
