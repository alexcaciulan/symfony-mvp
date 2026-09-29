<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\ClaimItem;
use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\ClaimItemKind;
use App\Enum\CourtType;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\Document\PaymentOrderRequestGeneratorService;
use App\Service\Document\SummonsContextBuilder;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The petition must claim exactly what the summons demanded: both are computed
 * by separate classes, so a change to one of them (credit notes, several
 * debtors) could otherwise make the two documents disagree silently.
 */
final class SummonsPaymentOrderConcordanceTest extends KernelTestCase
{
    use CountyFixtureTrait;

    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->user = new User();
        $this->user->setEmail('concordance-' . uniqid() . '@test.com');
        $this->user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Avocat');
        $this->user->setLastName('Test');
        $this->em->persist($this->user);
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE ci FROM claim_item ci JOIN legal_case lc ON ci.legal_case_id = lc.id WHERE lc.user_id = ?', [$userId]);
        $conn->executeStatement('DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = ?)', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = ?', [$userId]);
        $conn->executeStatement("DELETE FROM court WHERE name = 'Judecătoria Concordanță'");
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{PenaltyType, ?string}>
     */
    public static function penaltyModes(): iterable
    {
        yield 'legal interest' => [PenaltyType::LEGAL_PENALIZATOARE, null];
        yield 'contractual penalty' => [PenaltyType::CONTRACTUAL, '0.100'];
    }

    #[DataProvider('penaltyModes')]
    public function testPaymentOrderClaimsTheSameSumsAsTheSummons(PenaltyType $penaltyType, ?string $rate): void
    {
        $case = $this->persistCase($penaltyType, $rate);

        $summons = static::getContainer()->get(SummonsContextBuilder::class)->build($case);
        $petition = static::getContainer()->get(PaymentOrderRequestGeneratorService::class)->renderHtml($case);

        self::assertGreaterThan(0.0, $summons['accessoryTotal']);
        self::assertStringContainsString(self::money($summons['principal']), $petition);
        self::assertStringContainsString(self::money($summons['accessoryTotal']), $petition);
        self::assertStringContainsString(self::money($summons['grandTotal']), $petition);
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }

    private function persistCase(PenaltyType $penaltyType, ?string $rate): LegalCase
    {
        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Concordanta Creditor SRL');
        $creditor->setAddress('Str. Creditor 1, București');
        $creditor->setCui('RO11111111');
        $this->em->persist($creditor);

        $court = new Court();
        $court->setName('Judecătoria Concordanță');
        $court->setCounty($this->createCounty($this->em, 'București'));
        $court->setType(CourtType::JUDECATORIE);
        $court->setActive(true);
        $this->em->persist($court);

        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCreditor($creditor);
        $case->setCourt($court);
        $case->setRelationshipType(RelationshipType::COMERCIAL);
        $case->setPenaltyType($penaltyType);
        $case->setContractualPenaltyRate($rate);
        // Principal as the wizard stores it: invoices minus the credit note.
        $case->setAmount('14500.00');
        $case->setCurrency('RON');
        $case->setDueDate(new \DateTime('2025-03-10'));
        // A fixed past notice date: a petition that recomputed as of today
        // would print a different accessory and fail the comparison.
        $case->setPaymentNoticeDate(new \DateTime('2026-02-01'));
        $this->em->persist($case);

        $case->addClaimItem($this->item(ClaimItemKind::INVOICE, '10000.00', '2025-03-10', 'inv:1'));
        $case->addClaimItem($this->item(ClaimItemKind::INVOICE, '6000.00', '2025-06-20', 'inv:2'));
        $case->addClaimItem($this->item(ClaimItemKind::CREDIT_NOTE, '1500.00', null, 'cn:1'));

        $debtor = new Debtor();
        $debtor->setLegalCase($case);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Concordanta Debtor SRL');
        $debtor->setAddress('Str. Debtor 2, București');
        $debtor->setCui('RO22222222');
        $this->em->persist($debtor);
        $case->addDebtor($debtor);

        $this->em->flush();

        return $case;
    }

    private function item(ClaimItemKind $kind, string $amount, ?string $dueDate, string $dedupKey): ClaimItem
    {
        $item = new ClaimItem();
        $item->setKind($kind);
        $item->setAmount($amount);
        $item->setAmountRon($amount);
        $item->setCurrency('RON');
        $item->setDueDate($dueDate !== null ? new \DateTimeImmutable($dueDate) : null);
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey($dedupKey);
        $this->em->persist($item);

        return $item;
    }
}
