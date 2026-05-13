<?php

namespace App\Command;

use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\LegalGroundCategory;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Repository\CourtRepository;
use App\Repository\CreditorRepository;
use App\Repository\LegalCaseRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-demo-cases',
    description: 'Creează 5 dosare demo în statusuri diferite pentru avocatul de test (idempotent).',
)]
class SeedDemoCasesCommand extends Command
{
    private const LAWYER_EMAIL = 'avocat@test.com';

    /** @var array<int, array{status: CaseStatus, amount: string, debtorPersonType: PersonType, debtorName: string, debtorCui: ?string, debtorPersonalId: ?string, courtCaseNumber: ?string, hearingOffsetDays: ?int, dueOffsetDays: int}> */
    private const CASES = [
        [
            'status' => CaseStatus::AMIABIL,
            'amount' => '2500.00',
            'debtorPersonType' => PersonType::PF,
            'debtorName' => 'Popescu Ion',
            'debtorCui' => null,
            'debtorPersonalId' => '1850101123456',
            'courtCaseNumber' => null,
            'hearingOffsetDays' => null,
            'dueOffsetDays' => -45,
        ],
        [
            'status' => CaseStatus::SOMATIE_TRIMISA,
            'amount' => '7800.00',
            'debtorPersonType' => PersonType::PJ,
            'debtorName' => 'Restanțier SRL',
            'debtorCui' => 'RO87654321',
            'debtorPersonalId' => null,
            'courtCaseNumber' => null,
            'hearingOffsetDays' => null,
            'dueOffsetDays' => -90,
        ],
        [
            'status' => CaseStatus::ORDONANTA_EMISA,
            'amount' => '12500.00',
            'debtorPersonType' => PersonType::PJ,
            'debtorName' => 'Datornic Trans SA',
            'debtorCui' => 'RO11223344',
            'debtorPersonalId' => null,
            'courtCaseNumber' => 'DEMO-3/2026',
            'hearingOffsetDays' => -30,
            'dueOffsetDays' => -180,
        ],
        [
            'status' => CaseStatus::DEFINITIVA,
            'amount' => '4200.00',
            'debtorPersonType' => PersonType::PF,
            'debtorName' => 'Ionescu Maria',
            'debtorCui' => null,
            'debtorPersonalId' => '2900520123456',
            'courtCaseNumber' => 'DEMO-4/2026',
            'hearingOffsetDays' => -60,
            'dueOffsetDays' => -240,
        ],
        [
            'status' => CaseStatus::RESPINSA,
            'amount' => '1800.00',
            'debtorPersonType' => PersonType::PF,
            'debtorName' => 'Georgescu Vasile',
            'debtorCui' => null,
            'debtorPersonalId' => '1750815123456',
            'courtCaseNumber' => 'DEMO-5/2026',
            'hearingOffsetDays' => -45,
            'dueOffsetDays' => -300,
        ],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private CreditorRepository $creditorRepo,
        private CourtRepository $courtRepo,
        private LegalCaseRepository $legalCaseRepo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lawyer = $this->userRepo->findOneBy(['email' => self::LAWYER_EMAIL]);
        if (!$lawyer) {
            $io->error(sprintf('User %s lipsește. Rulează app:create-test-users mai întâi.', self::LAWYER_EMAIL));
            return Command::FAILURE;
        }

        $creditor = $this->getOrCreateDemoCreditor($lawyer);
        $courts = $this->courtRepo->findBy([], ['id' => 'ASC'], 5);
        if (count($courts) === 0) {
            $io->error('Nu există courts în DB. Rulează app:import-courts mai întâi.');
            return Command::FAILURE;
        }

        $created = 0;
        $skipped = 0;
        $deadlinesCreated = 0;
        $now = new \DateTimeImmutable();

        foreach (self::CASES as $idx => $row) {
            $caseNumber = sprintf('LR-DEMO-%s', $row['status']->value);

            if ($this->legalCaseRepo->findOneBy(['caseNumber' => $caseNumber]) !== null) {
                $skipped++;
                continue;
            }

            $case = new LegalCase();
            $case->setCaseNumber($caseNumber);
            $case->setUser($lawyer);
            $case->setCreditor($creditor);
            $case->setCourt($courts[$idx % count($courts)]);
            $case->setStatus($row['status']);
            $case->setRelationshipType(RelationshipType::COMERCIAL);
            $case->setAmount($row['amount']);
            $case->setCurrency('RON');
            $case->setDueDate(new \DateTime($now->modify(sprintf('%+d days', $row['dueOffsetDays']))->format('Y-m-d')));

            if ($this->isAtOrAfter($row['status'], CaseStatus::SOMATIE_TRIMISA)) {
                $case->setPaymentNoticeDate(new \DateTime($now->modify('-30 days')->format('Y-m-d')));
            }
            if ($row['courtCaseNumber'] !== null) {
                $case->setCourtCaseNumber($row['courtCaseNumber']);
            }
            if ($row['hearingOffsetDays'] !== null) {
                $case->setHearingDate(new \DateTime($now->modify(sprintf('%+d days', $row['hearingOffsetDays']))->format('Y-m-d')));
            }
            if ($row['status'] === CaseStatus::DEFINITIVA) {
                $case->setFinalRulingDate(new \DateTime($now->modify('-15 days')->format('Y-m-d')));
            }

            $debtor = new Debtor();
            $debtor->setLegalCase($case);
            $debtor->setPersonType($row['debtorPersonType']);
            $debtor->setName($row['debtorName']);
            $debtor->setCui($row['debtorCui']);
            $debtor->setPersonalId($row['debtorPersonalId']);
            $debtor->setAddress('Str. Demo nr. ' . ($idx + 1) . ', București');
            $case->addDebtor($debtor);

            foreach ($this->deadlinesFor($row['status'], $now, $case) as $deadline) {
                $this->em->persist($deadline);
                $deadlinesCreated++;
            }

            $this->em->persist($case);
            $this->em->persist($debtor);

            // Attach one fake "already-extracted" document to the first case so
            // Pas 3.0 manual smoke tests have realistic-looking extractedData
            // in the DB without uploading anything through the wizard. Pas 7.2
            // case_view will render this.
            if ($idx === 0) {
                $this->em->persist($this->buildDemoDocument($case, $lawyer, $row));
            }

            $created++;
        }

        $this->em->flush();

        $io->success(sprintf(
            'Demo dosare: %d create, %d skipped (deja existente). Termene noi: %d.',
            $created,
            $skipped,
            $deadlinesCreated,
        ));

        return Command::SUCCESS;
    }

    private function getOrCreateDemoCreditor(User $lawyer): Creditor
    {
        $existing = $this->creditorRepo->findOneBy(['user' => $lawyer, 'cui' => 'RO12345678']);
        if ($existing !== null) {
            return $existing;
        }

        $creditor = new Creditor();
        $creditor->setUser($lawyer);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('Demo Recovery SRL');
        $creditor->setCui('RO12345678');
        $creditor->setOnrcNumber('J40/1234/2020');
        $creditor->setAddress('Bd. Demo 100, București, Sector 1');
        $creditor->setEmail('contact@demo-recovery.ro');
        $creditor->setPhone('0721234567');
        $creditor->setIban('RO49AAAA1B31007593840000');
        $creditor->setLegalRepresentative('Demo Administrator');
        $this->em->persist($creditor);
        $this->em->flush();

        return $creditor;
    }

    /**
     * @return iterable<LegalDeadline>
     */
    private function deadlinesFor(CaseStatus $status, \DateTimeImmutable $now, LegalCase $case): iterable
    {
        $deadlines = match ($status) {
            CaseStatus::AMIABIL => [
                ['type' => DeadlineType::RASPUNS_SOMATIE, 'offset' => 30, 'priority' => DeadlinePriority::MEDIUM, 'desc' => 'Termen estimativ răspuns somație'],
            ],
            CaseStatus::SOMATIE_TRIMISA => [
                ['type' => DeadlineType::RASPUNS_SOMATIE, 'offset' => 5, 'priority' => DeadlinePriority::HIGH, 'desc' => 'Răspuns somație (urgent)'],
            ],
            CaseStatus::ORDONANTA_EMISA => [
                ['type' => DeadlineType::CERERE_IN_ANULARE, 'offset' => 10, 'priority' => DeadlinePriority::HIGH, 'desc' => 'Termen cerere în anulare ordonanță'],
            ],
            default => [],
        };

        foreach ($deadlines as $d) {
            $deadline = new LegalDeadline();
            $deadline->setLegalCase($case);
            $deadline->setType($d['type']);
            $deadline->setDeadlineDate(new \DateTimeImmutable($now->modify(sprintf('%+d days', $d['offset']))->format('Y-m-d')));
            $deadline->setPriority($d['priority']);
            $deadline->setDescription($d['desc']);
            $deadline->setCompleted(false);
            yield $deadline;
        }
    }

    /**
     * @param array{amount: string, dueOffsetDays: int, ...} $row
     */
    private function buildDemoDocument(LegalCase $case, User $lawyer, array $row): Document
    {
        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setOriginalFilename('demo-factura.pdf');
        $document->setStoredFilename('cases/demo/demo-factura.pdf');
        $document->setFileSize(124_000);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($lawyer);
        $document->setExtractionStatus(ExtractionStatus::COMPLETED);
        $document->setExtractionConfidence('0.87');
        $document->setExtractionStrategy('pdf_parser');
        $document->setExtractedData([
            'sourceDocumentId' => 0,
            'strategy' => 'pdf_parser',
            'globalConfidence' => 0.87,
            'extractedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'creditor' => [
                'personType' => PersonType::PJ->value,
                'name' => 'Demo Recovery SRL',
                'cui' => 'RO12345678',
                'isVatPayer' => true,
                'personalId' => null,
                'onrcNumber' => 'J40/1234/2020',
                'address' => 'Bd. Demo 100, București, Sector 1',
                'email' => 'contact@demo-recovery.ro',
                'phone' => '0721234567',
                'iban' => 'RO49AAAA1B31007593840000',
                'legalRepresentative' => 'Demo Administrator',
                'confidencePerField' => [
                    'name' => 0.95,
                    'cui' => 0.99,
                    'onrcNumber' => 0.9,
                    'address' => 0.88,
                    'email' => 0.85,
                    'phone' => 0.8,
                    'iban' => 0.92,
                    'legalRepresentative' => 0.78,
                    'personType' => 0.99,
                ],
            ],
            'debtor' => null,
            'claim' => [
                'amount' => (float) $row['amount'],
                'currency' => 'RON',
                'dueDate' => (new \DateTimeImmutable(sprintf('%+d days', $row['dueOffsetDays'])))->format(\DateTimeInterface::ATOM),
                'legalGround' => LegalGroundCategory::FACTURA_ACCEPTATA->value,
                'description' => 'Factură demo nr. 142/2025',
                'confidencePerField' => [
                    'amount' => 0.97,
                    'dueDate' => 0.91,
                    'legalGround' => 0.85,
                ],
            ],
            'rawOcrText' => null,
        ]);

        return $document;
    }

    private function isAtOrAfter(CaseStatus $status, CaseStatus $threshold): bool
    {
        $order = [
            CaseStatus::AMIABIL->value => 0,
            CaseStatus::SOMATIE_TRIMISA->value => 1,
            CaseStatus::CERERE_DEPUSA->value => 2,
            CaseStatus::DOSAR_INREGISTRAT->value => 3,
            CaseStatus::TERMEN_FIXAT->value => 4,
            CaseStatus::ORDONANTA_EMISA->value => 5,
            CaseStatus::IN_ANULARE->value => 6,
            CaseStatus::DEFINITIVA->value => 7,
            CaseStatus::EXECUTARE->value => 8,
            CaseStatus::INCHIS_SUCCES->value => 9,
            CaseStatus::INCHIS_PARTIAL_INSOLVABIL->value => 10,
            CaseStatus::RESPINSA->value => 11,
        ];
        return ($order[$status->value] ?? 0) >= ($order[$threshold->value] ?? 0);
    }
}
