<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\PersonType;
use App\Enum\StampDutyStatus;
use App\Service\Pdf\HtmlToPdfRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * A case ready for its filing package, with real PDF and image files on disk: the
 * package tools refuse the `%PDF-1.4 mock` stubs other tests use.
 */
trait FilingCaseFixtureTrait
{
    use CountyFixtureTrait;

    private string $filingUploadsDir;

    /** @var list<Court> */
    private array $filingCourts = [];

    private function createFilingUser(EntityManagerInterface $em, string $prefix): User
    {
        $user = new User();
        $user->setEmail($prefix . '-' . uniqid() . '@test.com');
        $user->setPassword('not-used');
        $user->setIsVerified(true);
        $user->setFirstName('Ana');
        $user->setLastName('Avocat');
        $em->persist($user);

        return $user;
    }

    private function createFilingCase(EntityManagerInterface $em, User $user, CaseStatus $status = CaseStatus::CERERE_GENERATA): LegalCase
    {
        $creditor = new Creditor();
        $creditor->setUser($user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Creditor Pachet SRL');
        $creditor->setAddress('Str. Pachet 1, București');
        $creditor->setCui('RO99993333');
        $em->persist($creditor);

        $court = new Court();
        $court->setName('Judecătoria Test Filing ' . uniqid());
        $court->setCounty($this->createCounty($em, 'București'));
        $court->setType(CourtType::JUDECATORIE);
        $court->setActive(true);
        $em->persist($court);
        $this->filingCourts[] = $court;

        $case = new LegalCase();
        $case->setUser($user);
        $case->setCreditor($creditor);
        $case->setCourt($court);
        $case->setStatus($status);
        $case->setAmount('5000.00');
        $case->setCurrency('RON');
        $case->setDueDate(new \DateTime('2024-06-15'));
        $case->setPaymentNoticeDate(new \DateTime('2026-02-01'));
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-02-03'));
        $case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $em->persist($case);

        $debtor = new Debtor();
        $debtor->setUser($user);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Debitor Pachet SRL');
        $debtor->setAddress('Str. Pachet 2, București');
        $debtor->setCui('RO99994444');
        $em->persist($debtor);
        $case->addDebtor(new LegalCaseDebtor($debtor));

        $em->flush();

        return $case;
    }

    /**
     * Everything the package carries, plus an issued order that must stay out of it.
     *
     * @return array<string, Document>
     */
    private function attachFilingDocuments(EntityManagerInterface $em, LegalCase $case, User $user, bool $withStampProof = true): array
    {
        $docs = [];
        $docs['petition'] = $this->attachPdf($em, $case, $user, DocumentType::CERERE_OP, 'Cerere_OP.pdf', [
            'CERERE DE ORDONANȚĂ DE PLATĂ. Dobândă acumulată, calculată până la 01.02.2026. Taxă judiciară de timbru: 200,00 RON, achitată conform dovezii anexate.',
            'Pagina a doua a cererii.',
        ]);
        $docs['somatie'] = $this->attachPdf($em, $case, $user, DocumentType::SOMATIE, 'Somatie.pdf', ['Somatie de plata', 'Somatie pagina 2']);
        $docs['communication'] = $this->attachPdf($em, $case, $user, DocumentType::DOVADA_COMUNICARE, 'Dovada_comunicare.pdf', ['Confirmare de primire']);
        $docs['poa'] = $this->attachPdf($em, $case, $user, DocumentType::IMPUTERNICIRE_AVOCATIALA, 'Imputernicire.pdf', ['Imputernicire avocatiala'], static function (Document $d): void {
            $d->setPowerOfAttorneySeries('B');
            $d->setPowerOfAttorneyNumber('1234');
            $d->setPowerOfAttorneyDate(new \DateTimeImmutable('2026-01-15'));
        });
        $docs['contract'] = $this->attachPdf($em, $case, $user, DocumentType::CONTRACT, 'Contract.pdf', ['Contract pagina 1', 'Contract pagina 2', 'Contract pagina 3']);
        $docs['invoice'] = $this->attachPng($em, $case, $user, DocumentType::FACTURA, 'Factura_foto.png');
        if ($withStampProof) {
            $docs['stamp'] = $this->attachPdf($em, $case, $user, DocumentType::DOVADA_TAXA_TIMBRU, 'Dovada_taxa.pdf', ['Ordin de plata taxa timbru']);
        }
        $docs['order'] = $this->attachPdf($em, $case, $user, DocumentType::ORDONANTA_PLATA, 'Ordonanta.pdf', ['Ordonanta emisa']);

        return $docs;
    }

    /**
     * @param list<string> $pages
     */
    private function attachPdf(EntityManagerInterface $em, LegalCase $case, User $user, DocumentType $type, string $name, array $pages, ?callable $configure = null): Document
    {
        return $this->attachBytes($em, $case, $user, $type, $name, self::pdfBytes($pages), 'application/pdf', $configure);
    }

    private function attachPng(EntityManagerInterface $em, LegalCase $case, User $user, DocumentType $type, string $name): Document
    {
        return $this->attachBytes($em, $case, $user, $type, $name, self::pngBytes(120, 90), 'image/png');
    }

    private function attachBytes(EntityManagerInterface $em, LegalCase $case, User $user, DocumentType $type, string $name, string $bytes, string $mime, ?callable $configure = null): Document
    {
        $dir = $this->filingUploadsDir . '/cases/' . $case->getId();
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $stored = 'cases/' . $case->getId() . '/fx-' . bin2hex(random_bytes(4)) . '-' . $name;
        file_put_contents($this->filingUploadsDir . '/' . $stored, $bytes);

        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType($type);
        $document->setOriginalFilename($name);
        $document->setStoredFilename($stored);
        $document->setFileSize(strlen($bytes));
        $document->setMimeType($mime);
        $document->setUploadedBy($user);
        $document->setExtractionStatus(ExtractionStatus::COMPLETED);
        if ($configure !== null) {
            $configure($document);
        }
        $case->addDocument($document);
        $em->persist($document);
        $em->flush();

        return $document;
    }

    /** @param list<string> $pages */
    private static function pdfBytes(array $pages): string
    {
        $html = '<html><body style="font-family: DejaVu Sans">';
        foreach ($pages as $i => $page) {
            $html .= ($i > 0 ? '<div style="page-break-before: always"></div>' : '') . '<p>' . htmlspecialchars($page) . '</p>';
        }
        $html .= '</body></html>';

        $path = tempnam(sys_get_temp_dir(), 'fxpdf');
        (new HtmlToPdfRenderer(new Environment(new ArrayLoader(['t' => $html]))))->render('t', [], $path);
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /** A valid grey PNG built by hand: GD is not installed in the container. */
    private static function pngBytes(int $width, int $height): string
    {
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0" . str_repeat(chr(($y * 2) % 256), $width * 3);
        }
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($raw))
            . $chunk('IEND', '');
    }

    private static function encryptPdf(string $bytes): string
    {
        $in = tempnam(sys_get_temp_dir(), 'fxenc');
        $out = $in . '.enc.pdf';
        file_put_contents($in, $bytes);
        (new Process(['qpdf', '--encrypt', 'user-pass', 'owner-pass', '256', '--', $in, $out]))->mustRun();
        $encrypted = (string) file_get_contents($out);
        @unlink($in);
        @unlink($out);

        return $encrypted;
    }

    private function removeFilingFixtures(EntityManagerInterface $em, ?User $user, ?LegalCase $case): void
    {
        $caseId = $case?->getId();
        if ($caseId !== null) {
            self::removeDirectory($this->filingUploadsDir . '/cases/' . $caseId . '/filing');
            foreach (glob($this->filingUploadsDir . '/cases/' . $caseId . '/fx-*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->filingUploadsDir . '/cases/' . $caseId);
        }

        $userId = $user?->getId();
        if ($userId === null) {
            return;
        }

        $conn = $em->getConnection();
        $cases = 'SELECT id FROM legal_case WHERE user_id = ?';
        $courtIds = $conn->fetchFirstColumn('SELECT DISTINCT court_id FROM legal_case WHERE user_id = ? AND court_id IS NOT NULL', [$userId]);
        $conn->executeStatement('DELETE FROM filing_package_part WHERE package_id IN (SELECT id FROM filing_package WHERE legal_case_id IN (' . $cases . '))', [$userId]);
        $conn->executeStatement('UPDATE filing_package SET previous_version_id = NULL WHERE legal_case_id IN (' . $cases . ')', [$userId]);
        $conn->executeStatement('DELETE FROM filing_package WHERE legal_case_id IN (' . $cases . ')', [$userId]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (' . $cases . ')', [$userId]);
        $conn->executeStatement('DELETE FROM claim_item WHERE legal_case_id IN (' . $cases . ')', [$userId]);
        $conn->executeStatement('DELETE FROM document WHERE legal_case_id IN (' . $cases . ')', [$userId]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (' . $cases . ')', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (' . $cases . ')', [$userId]);
        $conn->executeStatement('DELETE FROM notification WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = ?', [$userId]);
        foreach ($this->filingCourts as $court) {
            $courtIds[] = $court->getId();
        }
        foreach (array_unique(array_filter($courtIds)) as $courtId) {
            $conn->executeStatement("DELETE FROM court WHERE id = ? AND name LIKE 'Judecătoria Test Filing %'", [$courtId]);
        }
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
