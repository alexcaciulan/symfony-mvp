<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Court;
use App\Entity\CourtEmailAddress;
use App\Entity\Creditor;
use App\Entity\AppSetting;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailSource;
use App\Enum\CourtEmailStatus;
use App\Enum\CourtType;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartRole;
use App\Enum\OpisPlacement;
use App\Enum\PaymentNoticeCommunicationMethod;
use App\Enum\PersonType;
use App\Enum\SignatureStatus;
use App\Enum\StampDutyStatus;
use App\Enum\SubscriptionStatus;
use App\Repository\AppSettingRepository;
use App\Service\Filing\FilingAnnexSelector;
use App\Service\Filing\FilingPackageFingerprint;
use App\Service\Filing\FilingSizePlanner;
use App\Service\Filing\FilingSourceManifestBuilder;
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

    private function createCourtEmailAddress(
        EntityManagerInterface $em,
        Court $court,
        string $email,
        CourtEmailPurpose $purpose,
        CourtEmailStatus $status = CourtEmailStatus::PROPOSED,
        ?\DateTimeImmutable $validatedAt = null,
        ?User $validatedBy = null,
        bool $filingDefault = false,
    ): CourtEmailAddress {
        $address = new CourtEmailAddress($court, $email, $purpose, CourtEmailSource::PORTAL_IMPORT, new \DateTimeImmutable('-1 year'));
        if ($status === CourtEmailStatus::VALIDATED) {
            $address->markValidated($validatedBy ?? throw new \LogicException('A validated address needs its validator.'), $validatedAt ?? new \DateTimeImmutable(), 'statement');
        } else {
            $address->setStatus($status);
        }
        $address->setFilingDefault($filingDefault);
        $em->persist($address);
        $em->flush();

        return $address;
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

    /** @var list<int> */
    private array $filingPlanIds = [];

    private function createFilingSubscription(EntityManagerInterface $em, User $user, SubscriptionStatus $status = SubscriptionStatus::ACTIVE): Subscription
    {
        $plan = new Plan();
        $plan->setName('filing-plan-' . uniqid());
        $plan->setPriceMonthly('99.00');
        $plan->setIncludedCases(5);
        $plan->setPricePerExtra('25.00');
        $plan->setIsActive(true);
        $plan->setIsTrial(false);
        $em->persist($plan);

        $subscription = new Subscription();
        $subscription->setUser($user);
        $subscription->setPlan($plan);
        $subscription->setStatus($status);
        $subscription->setCurrentPeriodStart(new \DateTimeImmutable('-1 day'));
        $subscription->setCurrentPeriodEnd(new \DateTimeImmutable('+1 month'));
        $subscription->setCasesConsumed(0);
        $em->persist($subscription);
        $em->flush();
        $this->filingPlanIds[] = (int) $plan->getId();

        return $subscription;
    }

    /**
     * A case every send condition holds for: documents on disk, the summons served
     * by a bailiff, a confirmed registry address for its court, an active
     * subscription and a package whose files all carry an accepted signature.
     * The package is assembled directly, without the PDF tools: its fingerprint is
     * the real one, so the staleness check sees it as current.
     */
    private function createSendableFilingCase(EntityManagerInterface $em, User $user, int $emails = 1): LegalCase
    {
        $case = $this->createFilingCase($em, $user);
        $case->setPaymentNoticeCommunicationMethod(PaymentNoticeCommunicationMethod::EXECUTOR);
        $this->attachFilingDocuments($em, $case, $user);
        $this->createCourtEmailAddress($em, $case->getCourt(), 'registratura-' . uniqid() . '@example.test', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-10 days'), $user, true);
        $this->createFilingSubscription($em, $user);
        $this->createSignedFilingPackage($em, $case, $user, $emails);
        $em->flush();

        return $case;
    }

    private function createSignedFilingPackage(EntityManagerInterface $em, LegalCase $case, User $user, int $emails = 1): FilingPackage
    {
        $container = static::getContainer();
        $placement = $container->get(AppSettingRepository::class)->get(AppSetting::KEY_FILING_OPIS_PLACEMENT) === 'separate' ? OpisPlacement::SEPARATE : OpisPlacement::MERGED;
        $selection = $container->get(FilingAnnexSelector::class)->select($case);
        $manifest = $container->get(FilingSourceManifestBuilder::class)->build($case, $selection, $placement);

        $package = new FilingPackage($case, 1, $placement, $user);
        $package->setSource(FilingPackageFingerprint::hash($manifest), $manifest);
        $package->setIncludesStampDutyProof($selection->stampDutyProofAnnexed);

        $volumes = max(1, $emails - 1);
        $layout = [[FilingPartRole::PETITION, null, 'Cererea de ordonanță de plată, cu opisul'], [FilingPartRole::POWER_OF_ATTORNEY, null, 'Împuternicirea avocațială']];
        for ($v = 1; $v <= $volumes; ++$v) {
            $layout[] = [FilingPartRole::ANNEX_BUNDLE, $v, $volumes > 1 ? "Anexe, volumul $v din $volumes" : 'Anexe'];
        }

        $tokensPerEmail = array_fill(0, $emails, []);
        foreach ($layout as $position => [$role, $volume, $title]) {
            $token = bin2hex(random_bytes(16));
            $dir = 'cases/' . $case->getId() . '/filing/v1';
            $unsigned = self::pdfBytes([$title . ' ' . $token]);
            $signed = $unsigned . "\n% signed " . $token . "\n";
            foreach (['unsigned' => $unsigned, 'signed' => $signed] as $kind => $bytes) {
                if (!is_dir($this->filingUploadsDir . '/' . $dir . '/' . $kind)) {
                    mkdir($this->filingUploadsDir . '/' . $dir . '/' . $kind, 0o755, true);
                }
                file_put_contents($this->filingUploadsDir . '/' . $dir . '/' . $kind . '/' . $token . '.pdf', $bytes);
            }

            $part = new FilingPackagePart($role, $position + 1, $title, 1, $dir . '/unsigned/' . $token . '.pdf', hash('sha256', $unsigned), strlen($unsigned), $token);
            if ($volume !== null) {
                $part->setVolume($volume, $volumes);
            }
            $part->recordSignedUpload($dir . '/signed/' . $token . '.pdf', hash('sha256', $signed), strlen($signed), $user, SignatureStatus::PRESENT_MATCHED, ['warnings' => []]);
            $package->addPart($part);

            $email = $role === FilingPartRole::ANNEX_BUNDLE && $emails > 1 ? (int) $volume : 0;
            $tokensPerEmail[$email][] = $token;
        }

        $package->setSizePlan(['budget' => 6_000_000, 'emails' => FilingSizePlanner::emailBytes($package->getParts(), $tokensPerEmail), 'annexRanges' => [], 'warnings' => [], 'skippedDocuments' => []]);
        $package->recomputeSignedTotals();
        $package->setStatus(FilingPackageStatus::SIGNED);
        $em->persist($package);
        $em->flush();

        return $package;
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
        // Received messages first: their links to the case and the submission are SET NULL,
        // which would leave them behind. Their raw files go with them.
        $inbound = $conn->fetchAllAssociative(
            'SELECT id, raw_path FROM inbound_mail WHERE legal_case_id IN (' . $cases . ') OR matched_submission_id IN (SELECT id FROM filing_submission WHERE legal_case_id IN (' . $cases . ')) OR uploaded_by_id = ?',
            [$userId, $userId, $userId],
        );
        if ($inbound !== []) {
            $conn->executeStatement('UPDATE court_email_address SET invalidated_by_inbound_mail_id = NULL WHERE invalidated_by_inbound_mail_id IN (' . implode(',', array_map('intval', array_column($inbound, 'id'))) . ')');
            $conn->executeStatement('DELETE FROM inbound_mail WHERE id IN (' . implode(',', array_map('intval', array_column($inbound, 'id'))) . ')');
            foreach (array_unique(array_column($inbound, 'raw_path')) as $rawPath) {
                if ((int) $conn->fetchOne('SELECT COUNT(*) FROM inbound_mail WHERE raw_path = ?', [$rawPath]) === 0) {
                    @unlink($this->filingUploadsDir . '/' . $rawPath);
                }
            }
        }
        // Reports keep pointing at a deleted case as NULL; they go with the case here.
        $conn->executeStatement('DELETE FROM court_email_address_report WHERE legal_case_id IN (' . $cases . ') OR reported_by_id = ? OR resolved_by_id = ?', [$userId, $userId, $userId]);
        $conn->executeStatement("DELETE FROM notification WHERE type IN ('court_email_reported', 'court_email_suggested') AND legal_case_id IS NULL");
        // Submissions next: their emails reference package parts, and a submission the package.
        $conn->executeStatement('DELETE FROM filing_submission WHERE legal_case_id IN (' . $cases . ')', [$userId]);
        $conn->executeStatement('DELETE FROM filing_package_part WHERE package_id IN (SELECT id FROM filing_package WHERE legal_case_id IN (' . $cases . '))', [$userId]);
        $conn->executeStatement('UPDATE filing_package SET previous_version_id = NULL WHERE legal_case_id IN (' . $cases . ')', [$userId]);
        $conn->executeStatement('DELETE FROM filing_package WHERE legal_case_id IN (' . $cases . ')', [$userId]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('UPDATE court_email_address SET validated_by_id = NULL WHERE validated_by_id = ?', [$userId]);
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
            // A court another fixture user's case still points at is left for that user's cleanup.
            if ((int) $conn->fetchOne('SELECT COUNT(*) FROM legal_case WHERE court_id = ?', [$courtId]) > 0) {
                continue;
            }
            $conn->executeStatement("DELETE FROM court_email_address WHERE court_id = ? AND court_id IN (SELECT id FROM court WHERE name LIKE 'Judecătoria Test Filing %')", [$courtId]);
            $conn->executeStatement("DELETE FROM court WHERE id = ? AND name LIKE 'Judecătoria Test Filing %'", [$courtId]);
        }
        $conn->executeStatement('DELETE FROM invoice WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM subscription WHERE user_id = ?', [$userId]);
        foreach ($this->filingPlanIds as $planId) {
            $conn->executeStatement('DELETE FROM plan WHERE id = ? AND id NOT IN (SELECT plan_id FROM subscription)', [$planId]);
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
