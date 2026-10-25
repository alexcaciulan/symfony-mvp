<?php

declare(strict_types=1);

namespace App\Tests\Service\Pdf;

use App\Service\Pdf\ProcessSandbox;
use App\Service\Pdf\QpdfRunner;
use App\Service\Pdf\UploadedPdfException;
use App\Service\Pdf\UploadedPdfValidator;
use App\Tests\Support\TestPdfSigner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadedPdfValidatorTest extends KernelTestCase
{
    private ProcessSandbox $sandbox;
    private string $scratch;

    protected function setUp(): void
    {
        if (!ProcessSandbox::binariesAvailable(['qpdf', 'gs'])) {
            self::markTestSkipped('qpdf and gs are required.');
        }

        self::bootKernel();
        $this->sandbox = new ProcessSandbox((string) static::getContainer()->getParameter('kernel.project_dir'));
        $this->sandbox->begin();
        $this->scratch = sys_get_temp_dir() . '/pdfval-' . bin2hex(random_bytes(4));
        mkdir($this->scratch);
    }

    protected function tearDown(): void
    {
        if (isset($this->sandbox)) {
            $this->sandbox->end();
            foreach (glob($this->scratch . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->scratch);
        }
        parent::tearDown();
    }

    public function testATextFileWithAPdfExtensionIsNotAPdf(): void
    {
        $this->assertRefused('filing.upload.error.not_pdf', $this->upload("Just some text\n", 'cerere_semnata.pdf'));
    }

    public function testAFileStartingWithThePdfMarkerButNotParsableAsPdfIsRefused(): void
    {
        // Magic bytes alone do not make a PDF: finfo or qpdf refuse it.
        $file = $this->upload("%PDF-1.7\nnot really a pdf\n", 'fals.pdf');

        try {
            $this->validator()->validate($file);
            self::fail('Expected a refusal.');
        } catch (UploadedPdfException $e) {
            self::assertContains($e->messageKey, ['filing.upload.error.not_pdf', 'filing.upload.error.unreadable']);
        }
    }

    public function testAFileWithoutThePdfMarkerIsRefusedWhateverItsName(): void
    {
        $bytes = (string) file_get_contents($this->fixture('unsigned.pdf'));

        $this->assertRefused('filing.upload.error.not_pdf', $this->upload(str_replace('%PDF-', '%XYZ-', substr($bytes, 0, 1024)) . substr($bytes, 1024), 'cerere.pdf'));
    }

    public function testEncryptedPdfIsRefused(): void
    {
        $this->assertRefused('filing.upload.error.encrypted', $this->uploadFixture('encrypted.pdf'));
    }

    public function testPdfWithJavaScriptIsRefusedAsActiveContent(): void
    {
        $this->assertRefused('filing.upload.error.active_content', $this->uploadFixture('javascript.pdf'));
    }

    public function testAFileOverTheLimitIsRefusedBeforeAnyParsing(): void
    {
        $validator = new UploadedPdfValidator($this->sandbox, new QpdfRunner($this->sandbox), 1000);

        try {
            $validator->validate($this->uploadFixture('signed-incremental.pdf'));
            self::fail('Expected a refusal.');
        } catch (UploadedPdfException $e) {
            self::assertSame('filing.upload.error.too_large', $e->messageKey);
        }
    }

    public function testAnUploadErrorOverTheServerLimitReadsAsTooLarge(): void
    {
        $path = $this->scratch . '/partial.pdf';
        file_put_contents($path, '');

        $this->assertRefused('filing.upload.error.too_large', new UploadedFile($path, 'mare.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true));
    }

    public function testASignedPdfIsAcceptedUnderAGeneratedNameWithItsBytesUnchanged(): void
    {
        $original = $this->fixture('signed-incremental.pdf');

        $validated = $this->validator()->validate($this->uploadFixture('signed-incremental.pdf', 'Cerere semnata (final).pdf'));

        self::assertSame(hash_file('sha256', $original), $validated->sha256);
        self::assertSame(hash_file('sha256', $original), hash_file('sha256', $validated->path));
        self::assertSame(filesize($original), $validated->bytes);
        self::assertSame(2, $validated->pageCount);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', basename($validated->path));
        self::assertStringStartsWith($this->sandbox->workDir(), $validated->path);
    }

    private function assertRefused(string $key, UploadedFile $file): void
    {
        try {
            $this->validator()->validate($file);
            self::fail('Expected a refusal with ' . $key);
        } catch (UploadedPdfException $e) {
            self::assertSame($key, $e->messageKey);
        }
    }

    private function validator(): UploadedPdfValidator
    {
        return new UploadedPdfValidator($this->sandbox, new QpdfRunner($this->sandbox));
    }

    private function uploadFixture(string $name, ?string $clientName = null): UploadedFile
    {
        return $this->upload((string) file_get_contents($this->fixture($name)), $clientName ?? $name);
    }

    private function upload(string $bytes, string $clientName): UploadedFile
    {
        $path = $this->scratch . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $clientName, 'application/pdf', null, true);
    }

    private function fixture(string $name): string
    {
        return TestPdfSigner::FIXTURE_DIR . '/' . $name;
    }
}
