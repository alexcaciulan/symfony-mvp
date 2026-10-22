<?php

/*
 * Generates the committed PDF fixtures for the signed-upload tests. Run once,
 * inside the PHP container, from the project root:
 *
 *   docker compose exec -T php php tests/fixtures/filing/generate-signed-fixtures.php
 *
 * The keys are self-signed test keys created with:
 *
 *   openssl req -x509 -newkey rsa:2048 -nodes -keyout test-signer.key -out test-signer.crt \
 *     -subj "/C=RO/O=LexRecovery Test/serialNumber=PNORO-1900101000000/CN=Test Avocat" -days 36500
 *   openssl req -x509 -key test-signer.key -out test-signer-expired.crt \
 *     -subj "/C=RO/O=LexRecovery Test/CN=Test Avocat" -not_before 20200101000000Z -not_after 20210101000000Z
 *   openssl req -x509 -newkey rsa:2048 -nodes -keyout test-tsa.key -out test-tsa.crt \
 *     -subj "/CN=LexRecovery Test TSA" -days 36500 (with extendedKeyUsage=critical,timeStamping)
 *
 * The serialNumber in the signer's subject stands in for the personal numeric code
 * a Romanian qualified certificate carries: the tests check it never reaches a report.
 * Signing uses only PHP's openssl extension and the openssl CLI (for the timestamp);
 * no signing library is involved. Tests that need a signature over a freshly built
 * package call TestPdfSigner at runtime with the same keys.
 */

declare(strict_types=1);

use App\Service\Pdf\HtmlToPdfRenderer;
use App\Tests\Support\TestPdfSigner;
use Symfony\Component\Process\Process;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$dir = __DIR__;
$tmp = sys_get_temp_dir() . '/lexrecovery-fixtures-' . bin2hex(random_bytes(4));
mkdir($tmp);

$html = '<html><body style="font-family: DejaVu Sans"><p>CERERE DE ORDONANTA DE PLATA fixture pagina 1</p>'
    . '<div style="page-break-before: always"></div><p>Pagina a doua a fixture-ului</p></body></html>';
(new HtmlToPdfRenderer(new Environment(new ArrayLoader(['t' => $html]))))->render('t', [], $tmp . '/plain.pdf');
(new Process(['qpdf', '--object-streams=generate', $tmp . '/plain.pdf', $dir . '/unsigned.pdf']))->mustRun();

$unsigned = $dir . '/unsigned.pdf';
TestPdfSigner::sign($unsigned, $dir . '/signed-incremental.pdf');

(new Process(['qpdf', '--object-streams=disable', $unsigned, $tmp . '/rewritten.pdf']))->mustRun();
TestPdfSigner::sign($tmp . '/rewritten.pdf', $dir . '/rewritten-then-signed.pdf');

TestPdfSigner::sign($dir . '/signed-incremental.pdf', $dir . '/two-signatures.pdf');

// An annotation appended after the signature: the signature no longer covers the file.
$signed = (string) file_get_contents($dir . '/signed-incremental.pdf');
file_put_contents($dir . '/incomplete-byterange.pdf', TestPdfSigner::appendUpdate($signed, [
    1000 => '<< /Type /Annot /Subtype /FreeText /Rect [100 100 300 140] /Contents (Suma 99999 lei) /DA (/Helv 12 Tf 0 g) >>',
]));

// One byte of the header inside the signed range: still a readable PDF, wrong digest.
$altered = $signed;
$altered[7] = $altered[7] === '4' ? '5' : '4';
file_put_contents($dir . '/altered-digest.pdf', $altered);

(new Process(['qpdf', '--encrypt', 'user-pass', 'owner-pass', '256', '--', $unsigned, $dir . '/encrypted.pdf']))->mustRun();

TestPdfSigner::appendCatalogEntry($unsigned, $dir . '/javascript.pdf', '/OpenAction', '{1} 0 R', [
    '<< /S /JavaScript /JS (app.alert\(1\)) >>',
]);

TestPdfSigner::sign($unsigned, $dir . '/cades.pdf', ['subFilter' => 'ETSI.CAdES.detached']);
TestPdfSigner::sign($unsigned, $dir . '/expired-cert.pdf', ['certificate' => $dir . '/test-signer-expired.crt']);
TestPdfSigner::sign($unsigned, $dir . '/unsupported-subfilter.pdf', ['subFilter' => 'adbe.pkcs7.sha1']);

// Long-term validation data appended after signing, as Adobe and DSS do.
$certDer = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', (string) file_get_contents($dir . '/test-signer.crt')), true);
TestPdfSigner::appendCatalogEntry($dir . '/signed-incremental.pdf', $dir . '/dss-tail.pdf', '/DSS', '{1} 0 R', [
    '<< /Type /DSS /Certs [{2} 0 R] >>',
    '<< /Length ' . strlen((string) $certDer) . " >>\nstream\n" . $certDer . "\nendstream",
]);

TestPdfSigner::timestamp($dir . '/signed-incremental.pdf', $dir . '/timestamped.pdf');

foreach (glob($tmp . '/*') ?: [] as $file) {
    unlink($file);
}
rmdir($tmp);

echo "Fixtures written to {$dir}\n";
