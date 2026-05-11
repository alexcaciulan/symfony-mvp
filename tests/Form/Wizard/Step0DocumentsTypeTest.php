<?php

declare(strict_types=1);

namespace App\Tests\Form\Wizard;

use App\Form\Wizard\Step0DocumentsType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Validation tests for the wizard step 0 multi-file form.
 *
 * We KernelTestCase (not the lighter TypeTestCase) because the form leans on
 * the real Symfony Validator with multiple constraints (Count + All[File]),
 * and re-wiring those by hand in a unit test would just re-test Symfony's
 * own validator wiring.
 */
final class Step0DocumentsTypeTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(FormFactoryInterface::class);
    }

    public function testValidPdfPasses(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'documents' => [$this->makeFakeUpload('contract.pdf', 'pdf')],
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    public function testEmptySubmitIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit(['documents' => []]);

        self::assertFalse($form->isValid());
    }

    public function testRejectedMimeIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'documents' => [$this->makeFakeUpload('exec.exe', 'exe')],
        ]);

        self::assertFalse($form->isValid());
    }

    public function testTooManyFilesIsInvalid(): void
    {
        $files = [];
        for ($i = 0; $i < 11; $i++) {
            $files[] = $this->makeFakeUpload("file-{$i}.pdf", 'pdf');
        }

        $form = $this->buildForm();
        $form->submit(['documents' => $files]);

        self::assertFalse($form->isValid());
    }

    public function testJpegAndPngAreAccepted(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'documents' => [
                $this->makeFakeUpload('scan.jpg', 'jpg'),
                $this->makeFakeUpload('scan.png', 'png'),
            ],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    private function buildForm(): \Symfony\Component\Form\FormInterface
    {
        // CSRF is exercised by the controller integration tests; here we
        // focus on the validation rules (Count + All[File]) and bypass CSRF
        // because the test submit() doesn't have a real session/token.
        return $this->factory->create(Step0DocumentsType::class, null, ['csrf_protection' => false]);
    }

    /**
     * Writes real magic-byte content for the requested format so the File
     * constraint (which sniffs MIME from disk) accepts the file as that type.
     * We don't write a structurally-valid PDF/JPEG/PNG — just enough header
     * bytes for libmagic to classify the content.
     */
    private function makeFakeUpload(string $originalName, string $format): UploadedFile
    {
        $headers = [
            'pdf' => "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n",
            // JFIF JPEG: SOI + JFIF APP0 marker
            'jpg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00",
            // PNG signature + IHDR chunk header
            'png' => "\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00",
            // Windows PE (rejected by allow-list)
            'exe' => "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00",
        ];
        $header = $headers[$format] ?? throw new \LogicException("Unknown fixture format: $format");

        $tmp = tempnam(sys_get_temp_dir(), 'step0-');
        if ($tmp === false) {
            throw new \RuntimeException('Failed to create temp file for upload fixture');
        }
        file_put_contents($tmp, $header . str_repeat("\0", 256));

        // No explicit mimeType arg — let UploadedFile / MimeTypes sniff the
        // magic bytes we just wrote. test:true to skip is_uploaded_file().
        return new UploadedFile(
            path: $tmp,
            originalName: $originalName,
            error: UPLOAD_ERR_OK,
            test: true,
        );
    }
}
