<?php

declare(strict_types=1);

namespace App\Tests\Service\Pdf;

use App\Service\Pdf\PdfSignatureInspector;
use App\Service\Pdf\ProcessSandbox;
use App\Service\Pdf\QpdfRunner;
use App\Service\Pdf\SignatureInspection;
use App\Tests\Support\TestPdfSigner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PdfSignatureInspectorTest extends KernelTestCase
{
    private PdfSignatureInspector $inspector;
    private ProcessSandbox $sandbox;

    protected function setUp(): void
    {
        if (!ProcessSandbox::binariesAvailable(['qpdf'])) {
            self::markTestSkipped('qpdf is required.');
        }

        self::bootKernel();
        $this->sandbox = new ProcessSandbox((string) static::getContainer()->getParameter('kernel.project_dir'));
        $this->inspector = new PdfSignatureInspector($this->sandbox, new QpdfRunner($this->sandbox));
        $this->sandbox->begin();
    }

    protected function tearDown(): void
    {
        if (isset($this->sandbox)) {
            $this->sandbox->end();
        }
        parent::tearDown();
    }

    public function testUnsignedFileHasNoSignature(): void
    {
        self::assertSame(SignatureInspection::NONE, $this->inspect('unsigned.pdf')->outcome);
    }

    public function testIncrementalSignatureIsIntactAndReportsOnlyTheCommonNames(): void
    {
        $result = $this->inspect('signed-incremental.pdf');

        self::assertSame(SignatureInspection::INTACT, $result->outcome);
        self::assertSame('adbe.pkcs7.detached', $result->subFilter);
        self::assertSame(1, $result->signatureCount);
        self::assertSame('Test Avocat', $result->signerCn);
        self::assertSame('Test Avocat', $result->issuerCn);
        self::assertSame('cms', $result->signingTimeSource);
        self::assertSame([], $result->warnings);
        self::assertSame(filesize($this->fixture('signed-incremental.pdf')), $result->coveredLength);

        // The certificate subject carries a personal code; nothing but the CN may leave.
        $report = json_encode($result->toReport(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PNORO', $report);
        self::assertStringNotContainsString('serialNumber', $report);
        self::assertStringNotContainsString('LexRecovery Test', $report);
    }

    public function testSignatureOverARewrittenFileIsIntact(): void
    {
        self::assertSame(SignatureInspection::INTACT, $this->inspect('rewritten-then-signed.pdf')->outcome);
    }

    public function testTwoSignaturesAreIntactAndTheLastOneCoversTheFile(): void
    {
        $result = $this->inspect('two-signatures.pdf');

        self::assertSame(SignatureInspection::INTACT, $result->outcome);
        self::assertSame(2, $result->signatureCount);
        self::assertSame(filesize($this->fixture('two-signatures.pdf')), $result->coveredLength);
    }

    public function testContentAppendedAfterTheSignatureIsDamaged(): void
    {
        self::assertSame(SignatureInspection::DAMAGED, $this->inspect('incomplete-byterange.pdf')->outcome);
    }

    public function testAByteChangedInsideTheSignedRangeIsDamaged(): void
    {
        self::assertSame(SignatureInspection::DAMAGED, $this->inspect('altered-digest.pdf')->outcome);
    }

    public function testCadesSignatureIsIntact(): void
    {
        $result = $this->inspect('cades.pdf');

        self::assertSame(SignatureInspection::INTACT, $result->outcome);
        self::assertSame('ETSI.CAdES.detached', $result->subFilter);
    }

    public function testASubFilterOutsideTheSupportedOnesIsUnsupported(): void
    {
        $path = $this->sandbox->tempPath();
        TestPdfSigner::sign($this->fixture('unsigned.pdf'), $path, ['subFilter' => 'adbe.x509.rsa_sha1']);

        $result = $this->inspector->inspect($path);

        self::assertSame(SignatureInspection::UNSUPPORTED, $result->outcome);
        self::assertSame('adbe.x509.rsa_sha1', $result->subFilter);
    }

    /** The outdated SHA-1 format is a technical finding with a warning, never declared invalid. */
    public function testALegacySha1SignatureIsIntactWithAWarning(): void
    {
        $path = $this->sandbox->tempPath();
        TestPdfSigner::sign($this->fixture('unsigned.pdf'), $path, ['subFilter' => 'adbe.pkcs7.sha1']);

        $result = $this->inspector->inspect($path);

        self::assertSame(SignatureInspection::INTACT, $result->outcome);
        self::assertSame('adbe.pkcs7.sha1', $result->subFilter);
        self::assertSame('Test Avocat', $result->signerCn);
        self::assertSame(['filing.signature.warning.legacy_sub_filter'], $result->warnings);
    }

    public function testALegacySha1SignatureOverChangedBytesIsDamaged(): void
    {
        $path = $this->sandbox->tempPath();
        TestPdfSigner::sign($this->fixture('unsigned.pdf'), $path, ['subFilter' => 'adbe.pkcs7.sha1']);
        $bytes = (string) file_get_contents($path);
        $bytes[7] = $bytes[7] === '4' ? '5' : '4';
        file_put_contents($path, $bytes);

        self::assertSame(SignatureInspection::DAMAGED, $this->inspector->inspect($path)->outcome);
    }

    public function testASignatureOnTheLastPageOrWithoutAVisibleWidgetIsFound(): void
    {
        foreach ([['page' => -1, 'visibleRect' => [40, 60, 300, 100]], ['page' => -1], []] as $options) {
            $path = $this->sandbox->tempPath();
            TestPdfSigner::sign($this->fixture('unsigned.pdf'), $path, $options);

            $result = $this->inspector->inspect($path);

            self::assertSame(SignatureInspection::INTACT, $result->outcome, json_encode($options, JSON_THROW_ON_ERROR));
            self::assertSame(1, $result->signatureCount);
        }
    }

    /** It cannot be a valid signature, but it must never pass for an unsigned file. */
    public function testASignatureDictionaryInsideAnObjectStreamIsFound(): void
    {
        $path = $this->sandbox->tempPath();
        TestPdfSigner::appendSignatureInObjectStream($this->fixture('unsigned.pdf'), $path);

        $result = $this->inspector->inspect($path);

        self::assertSame(SignatureInspection::DAMAGED, $result->outcome);
        self::assertSame(1, $result->signatureCount);
    }

    public function testValidationDataAfterADocumentTimestampIsAccepted(): void
    {
        $path = $this->sandbox->tempPath();
        $this->appendDss($this->fixture('timestamped.pdf'), $path);

        $result = $this->inspector->inspect($path);

        self::assertSame(SignatureInspection::INTACT, $result->outcome);
        self::assertSame(2, $result->signatureCount);
        self::assertSame('Test Avocat', $result->signerCn);
        self::assertSame(['filing.signature.warning.timestamp_appended', 'filing.signature.warning.trailing_update'], $result->warnings);
    }

    public function testValidationDataCompressedInAnObjectStreamIsAccepted(): void
    {
        foreach (['signed-incremental.pdf', 'timestamped.pdf'] as $fixture) {
            $path = $this->sandbox->tempPath();
            TestPdfSigner::appendCompressedDss($this->fixture($fixture), $path);

            $result = $this->inspector->inspect($path);

            self::assertSame(SignatureInspection::INTACT, $result->outcome, $fixture);
            self::assertContains('filing.signature.warning.trailing_update', $result->warnings, $fixture);
        }
    }

    /** The byte scan cannot see inside a Flate object stream; the structural comparison does. */
    public function testAnAnnotationHiddenInACompressedUpdateAfterTheSignatureIsDamaged(): void
    {
        $annotation = '<< /Type /Annot /Subtype /FreeText /Rect [100 100 300 140] /Contents (Suma 99999 lei) /DA (/Helv 12 Tf 0 g) >>';
        foreach (['signed-incremental.pdf', 'timestamped.pdf'] as $fixture) {
            $path = $this->sandbox->tempPath();
            TestPdfSigner::appendCompressedDss($this->fixture($fixture), $path, $annotation);

            self::assertSame(SignatureInspection::DAMAGED, $this->inspector->inspect($path)->outcome, $fixture);
        }
    }

    /** A document timestamp is not a signer's signature. */
    public function testAFileWithOnlyADocumentTimestampIsNotAcceptedAsSigned(): void
    {
        $path = $this->sandbox->tempPath();
        TestPdfSigner::timestamp($this->fixture('unsigned.pdf'), $path);

        $result = $this->inspector->inspect($path);

        self::assertSame(SignatureInspection::UNSUPPORTED, $result->outcome);
        self::assertNull($result->signerCn);
    }

    public function testValidationDataAppendedAfterSigningIsAcceptedWithANeutralWarning(): void
    {
        $result = $this->inspect('dss-tail.pdf');

        self::assertSame(SignatureInspection::INTACT, $result->outcome);
        self::assertSame(['filing.signature.warning.trailing_update'], $result->warnings);
    }

    public function testDocumentTimestampAfterTheSignatureIsAcceptedAndTheSignerComesFromTheContentSignature(): void
    {
        $result = $this->inspect('timestamped.pdf');

        self::assertSame(SignatureInspection::INTACT, $result->outcome);
        self::assertSame(2, $result->signatureCount);
        self::assertSame('Test Avocat', $result->signerCn);
        self::assertSame('adbe.pkcs7.detached', $result->subFilter);
        self::assertSame(['filing.signature.warning.timestamp_appended'], $result->warnings);
    }

    public function testATimestampWhoseImprintNoLongerMatchesIsDamaged(): void
    {
        $bytes = (string) file_get_contents($this->fixture('timestamped.pdf'));
        // Inside the content signature's range and the timestamp's range alike.
        $bytes[7] = $bytes[7] === '4' ? '5' : '4';
        $path = $this->sandbox->tempPath();
        file_put_contents($path, $bytes);

        self::assertSame(SignatureInspection::DAMAGED, $this->inspector->inspect($path)->outcome);
    }

    public function testExpiredCertificateIsOnlyAWarning(): void
    {
        $result = $this->inspect('expired-cert.pdf');

        self::assertSame(SignatureInspection::INTACT, $result->outcome);
        self::assertSame(['filing.signature.warning.cert_not_valid_at_indicated_date'], $result->warnings);
        self::assertSame('2021-01-01', $result->notAfter?->format('Y-m-d'));
    }

    public function testASignatureNotLinkedIntoTheFormIsFoundByTheRawScan(): void
    {
        // Same signature bytes, but the AcroForm entry renamed so qpdf lists no field.
        $bytes = (string) file_get_contents($this->fixture('signed-incremental.pdf'));
        $start = (int) strrpos($bytes, '/AcroForm');
        $unlinked = substr_replace($bytes, '/AcroFxrm', $start, 9);
        $path = $this->sandbox->tempPath();
        file_put_contents($path, $unlinked);

        // The renamed key sits inside the signed range: found, and found damaged.
        self::assertSame(SignatureInspection::DAMAGED, $this->inspector->inspect($path)->outcome);
    }

    public function testASignatureAppliedAtRuntimeToAnUnsignedFileIsIntact(): void
    {
        $path = $this->sandbox->tempPath();
        TestPdfSigner::sign($this->fixture('unsigned.pdf'), $path);

        self::assertSame(SignatureInspection::INTACT, $this->inspector->inspect($path)->outcome);
    }

    private function appendDss(string $in, string $out): void
    {
        $certDer = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', (string) file_get_contents($this->fixture('test-signer.crt'))), true);
        TestPdfSigner::appendCatalogEntry($in, $out, '/DSS', '{1} 0 R', [
            '<< /Type /DSS /Certs [{2} 0 R] >>',
            '<< /Length ' . strlen((string) $certDer) . " >>\nstream\n" . $certDer . "\nendstream",
        ]);
    }

    private function inspect(string $name): SignatureInspection
    {
        return $this->inspector->inspect($this->fixture($name));
    }

    private function fixture(string $name): string
    {
        return TestPdfSigner::FIXTURE_DIR . '/' . $name;
    }
}
