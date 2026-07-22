<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\ClaimItem;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\ClaimItemKind;
use App\Enum\DocumentType;
use App\Enum\PersonType;
use App\Service\Case\ClaimTotalsService;
use App\Service\Document\OpisGeneratorService;
use App\Service\Document\PaymentOrderRequestGeneratorService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The claim positions where they touch the database and the filed documents.
 *
 * Covers what a lawyer excludes never reaching the court, the backfill that
 * every pre-existing case depends on, and the uniqueness the deduplication
 * ultimately rests on.
 */
final class ClaimItemPersistenceAdversarialTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private int $userId;
    private Creditor $creditor;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('claim-items-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Avocat');
        $this->user->setLastName('Pozitii');
        $this->user->setBarNumber('B-90210');
        $this->em->persist($this->user);

        $this->creditor = new Creditor();
        $this->creditor->setUser($this->user);
        $this->creditor->setPersonType(PersonType::PJ);
        $this->creditor->setName('SC Pozitii Creditor SRL');
        $this->creditor->setAddress('Str. Creditor 1, București');
        $this->creditor->setCui('RO55555555');
        $this->em->persist($this->creditor);

        $this->em->flush();
        $this->userId = (int) $this->user->getId();
        $this->connection = $this->em->getConnection();
    }

    protected function tearDown(): void
    {
        // A test that provokes a constraint violation closes the entity manager,
        // so cleanup goes through the connection, which stays usable.
        $userId = $this->userId;
        $conn = $this->connection;
        $conn->executeStatement('DELETE ci FROM claim_item ci JOIN legal_case lc ON ci.legal_case_id = lc.id WHERE lc.user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id IS NULL', []);
        $conn->executeStatement('DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = ?)', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);
        parent::tearDown();
    }

    /**
     * What the lawyer struck out is not in the totals, not in the petition and
     * not in the index. All three read the same gate, and all three are checked
     * here on rendered output rather than on the gate itself.
     */
    public function testExcludedAndUnconfirmedPositionsReachNeitherTheTotalsNorThePetitionNorTheIndex(): void
    {
        $case = $this->case();
        $documents = [];
        foreach (['A', 'B', 'X', 'Y'] as $suffix) {
            $documents[$suffix] = $this->document($case, 'anexa-' . strtolower($suffix) . '.pdf');
        }

        $counted = [
            $this->item($case, 'FF-A', '1000.00', '2025-01-31', $documents['A']),
            $this->item($case, 'FF-B', '2000.00', '2025-02-28', $documents['B']),
        ];
        $excluded = $this->item($case, 'FF-X', '5000.00', '2025-03-31', $documents['X']);
        $excluded->setExcludedByLawyer(true);
        $unconfirmed = $this->item($case, 'FF-Y', '7000.00', '2025-04-30', $documents['Y']);
        $unconfirmed->setConfirmedByLawyer(false);

        $this->em->flush();

        $totals = (new ClaimTotalsService())->recalculate($case);
        $this->em->flush();

        $this->assertSame(3000.0, $totals->principalRon);
        $this->assertSame('3000.00', $case->getAmount());
        $this->assertCount(2, $case->getCountingClaimItems());
        $this->assertSame(
            [$excluded->getId(), $unconfirmed->getId()],
            $totals->excludedItemIds,
        );

        $petition = static::getContainer()->get(PaymentOrderRequestGeneratorService::class)->renderHtml($case);
        foreach ($counted as $item) {
            $this->assertStringContainsString((string) $item->getDocumentNumber(), $petition);
        }
        $this->assertStringNotContainsString('FF-X', $petition);
        $this->assertStringNotContainsString('FF-Y', $petition);
        $this->assertStringNotContainsString('5.000,00', $petition);
        $this->assertStringNotContainsString('7.000,00', $petition);

        $index = static::getContainer()->get(OpisGeneratorService::class)->renderHtml($case);
        $this->assertStringContainsString('FF-A', $index);
        $this->assertStringContainsString('FF-B', $index);
        $this->assertStringNotContainsString('FF-X', $index);
        $this->assertStringNotContainsString('FF-Y', $index);
    }

    /**
     * The backfill statement of the migration, run verbatim against a case that
     * carries no position, which is exactly the state every pre-existing file
     * was in. A RON case gets a confirmed position keyed `legacy:<id>` holding
     * the figures the case already computed with.
     */
    public function testTheMigrationBackfillProducesOneConfirmedLegacyPositionPerRonCase(): void
    {
        $case = $this->case();
        $case->setAmount('12345.67');
        $case->setCurrency('RON');
        $case->setInvoiceNumber('FF-LEGACY-1');
        $case->setInvoiceDate(new \DateTime('2025-01-10'));
        $case->setDueDate(new \DateTime('2025-02-10'));
        $case->setContractNumber('Contract 7/2024');
        $this->em->flush();

        $this->runBackfillFor((int) $case->getId());

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM claim_item WHERE legal_case_id = ?',
            [$case->getId()]
        );

        $this->assertIsArray($row);
        $this->assertSame('legacy:' . $case->getId(), $row['dedup_key']);
        $this->assertSame(1, (int) $row['confirmed_by_lawyer']);
        $this->assertSame(0, (int) $row['excluded_by_lawyer']);
        $this->assertSame(0, (int) $row['needs_manual_fx']);
        $this->assertSame(ClaimItemKind::INVOICE->value, $row['kind']);
        $this->assertSame('12345.67', $row['amount']);
        $this->assertSame('12345.67', $row['amount_ron']);
        $this->assertSame('RON', $row['currency']);
        $this->assertSame('FF-LEGACY-1', $row['document_number']);
        $this->assertSame('2025-02-10', $row['due_date']);
        $this->assertSame('Contract 7/2024', $row['cause_reference']);

        $this->em->clear();
        /** @var LegalCase $reloaded */
        $reloaded = $this->em->find(LegalCase::class, $case->getId());
        $this->assertCount(1, $reloaded->getCountingClaimItems());
    }

    /**
     * A legacy case in a foreign currency has no rate behind its stored amount,
     * so the backfill must flag it rather than pretend the figure is RON. It
     * then stays out of the totals, and the case keeps the scalars it had.
     */
    public function testTheMigrationBackfillFlagsALegacyForeignCurrencyCase(): void
    {
        $case = $this->case();
        $case->setAmount('5000.00');
        $case->setCurrency('EUR');
        $case->setDueDate(new \DateTime('2024-02-10'));
        $this->em->flush();

        $this->runBackfillFor((int) $case->getId());

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM claim_item WHERE legal_case_id = ?',
            [$case->getId()]
        );

        $this->assertIsArray($row);
        $this->assertNull($row['amount_ron']);
        $this->assertSame(1, (int) $row['needs_manual_fx']);

        $this->em->clear();
        /** @var LegalCase $reloaded */
        $reloaded = $this->em->find(LegalCase::class, $case->getId());
        $this->assertSame([], $reloaded->getCountingClaimItems());

        (new ClaimTotalsService())->recalculate($reloaded);
        $this->assertSame('5000.00', $reloaded->getAmount());
        $this->assertSame('EUR', $reloaded->getCurrency());
    }

    /**
     * The unique index is the last line of defence behind the in-memory
     * deduplication: the same position written twice on one case is refused by
     * the database, and refused per case rather than globally.
     */
    public function testTheUniqueIndexRefusesTheSamePositionTwiceOnOneCase(): void
    {
        $case = $this->case();
        $other = $this->case();
        $this->em->flush();

        $this->insertRaw((int) $case->getId(), 'inv:duplicate');
        // The same key on another case is a different position and is allowed.
        $this->insertRaw((int) $other->getId(), 'inv:duplicate');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertRaw((int) $case->getId(), 'inv:duplicate');
    }

    /**
     * Nothing between the rows and the database re-checks the key, so the
     * in-memory deduplication in ClaimItemFactory is the only thing that keeps
     * a duplicate out. Pinned here so that adding positions to an existing case
     * cannot quietly become a 500.
     */
    public function testMaterializingTwoRowsWithTheSameKeyIsNotCaughtBeforeTheDatabase(): void
    {
        $case = $this->case();
        $first = $this->item($case, 'FF-DUP', '1000.00', '2025-01-31');
        $second = $this->item($case, 'FF-DUP', '1000.00', '2025-01-31');
        $second->setDedupKey($first->getDedupKey());

        $this->assertCount(2, $case->getClaimItems());
        $this->assertSame($first->getDedupKey(), $second->getDedupKey());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    private function runBackfillFor(int $caseId): void
    {
        $migration = file_get_contents(\dirname(__DIR__, 3) . '/migrations/Version20260722090000.php');
        $this->assertIsString($migration);
        $this->assertSame(1, preg_match("/<<<'SQL'(.*?)\n\s*SQL\);/s", $migration, $matches));

        $this->em->getConnection()->executeStatement(trim($matches[1]) . ' AND c.id = ' . $caseId);
    }

    private function insertRaw(int $caseId, string $dedupKey): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO claim_item (legal_case_id, kind, amount, currency, needs_manual_fx, paid_amount, dedup_key, confirmed_by_lawyer, excluded_by_lawyer, created_at)'
            . " VALUES (?, 'invoice', '100.00', 'RON', 0, '0.00', ?, 1, 0, NOW())",
            [$caseId, $dedupKey]
        );
    }

    private function case(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCreditor($this->creditor);
        $case->setAmount('1000.00');
        $case->setCurrency('RON');
        $case->setDueDate(new \DateTime('2025-01-31'));
        $this->em->persist($case);

        $debtor = new Debtor();
        $debtor->setLegalCase($case);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Pozitii Debtor SRL');
        $debtor->setAddress('Str. Debtor 2, București');
        $debtor->setCui('RO66666666');
        $this->em->persist($debtor);
        $case->addDebtor($debtor);

        return $case;
    }

    private function document(LegalCase $case, string $filename): Document
    {
        $document = new Document();
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setOriginalFilename($filename);
        $document->setStoredFilename('cases/test/' . $filename);
        $document->setFileSize(1024);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($this->user);
        $document->setLegalCase($case);
        $case->addDocument($document);
        $this->em->persist($document);

        return $document;
    }

    private function item(
        LegalCase $case,
        string $number,
        string $amount,
        string $dueDate,
        ?Document $source = null,
    ): ClaimItem {
        $item = new ClaimItem();
        $item->setKind(ClaimItemKind::INVOICE);
        $item->setDocumentNumber($number);
        $item->setDocumentDate(new \DateTimeImmutable($dueDate));
        $item->setDueDate(new \DateTimeImmutable($dueDate));
        $item->setAmount($amount);
        $item->setAmountRon($amount);
        $item->setCurrency('RON');
        $item->setDedupKey('inv:' . $number);
        $item->setConfirmedByLawyer(true);
        $item->setSourceDocument($source);
        $case->addClaimItem($item);
        $this->em->persist($item);

        return $item;
    }
}
