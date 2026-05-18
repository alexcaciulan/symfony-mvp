<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtType;
use App\Enum\DocumentType;
use App\Enum\PersonType;
use App\Service\Document\PaymentOrderRequestGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 5.2 — Tests for PaymentOrderRequestGeneratorService.
 *
 * Critical legal assertions: CPC art. 1014-1024 + raport COMERCIAL + numele
 * instanței obligatorii. Template trebuie să afișeze toate elementele cerute
 * de art. 1016 (instanță, părți, sume, temei, anexe).
 */
final class PaymentOrderRequestGeneratorServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PaymentOrderRequestGeneratorService $service;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(PaymentOrderRequestGeneratorService::class);

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('po-gen-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Avocat');
        $this->user->setLastName('Test');
        $this->user->setBarNumber('B-77777');
        $this->em->persist($this->user);

        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC PO Creditor SRL');
        $creditor->setAddress('Str. Creditor 1, București');
        $creditor->setCui('RO11111111');
        $this->em->persist($creditor);

        $court = new Court();
        $court->setName('Judecătoria Sector 1 București');
        $court->setCounty('București');
        $court->setType(CourtType::JUDECATORIE);
        $court->setActive(true);
        $this->em->persist($court);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setCourt($court);
        $this->case->setAmount('10000.00');
        $this->case->setCurrency('RON');
        $this->case->setCalculatedInterest('850.00');
        $this->case->setStampDuty('200.00');
        $this->case->setDueDate(new \DateTime('2024-06-15'));
        $this->case->setPaymentNoticeDate(new \DateTime('2026-02-01'));
        $this->em->persist($this->case);

        $debtor = new Debtor();
        $debtor->setLegalCase($this->case);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC PO Debtor SRL');
        $debtor->setAddress('Str. Debtor 2, București');
        $debtor->setCui('RO22222222');
        $this->em->persist($debtor);
        $this->case->addDebtor($debtor);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id IS NULL', []);
        $conn->executeStatement('DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = ?)', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = ?', [$userId]);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE 'Judecătoria Sector 1%'");
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);
        parent::tearDown();
    }

    public function testRenderHtmlContainsHeadingAndLegalBasis(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('CERERE DE ORDONANȚĂ DE PLATĂ', $html);
        self::assertStringContainsString('1014-1024', $html, 'Temeiul legal CPC art. 1014-1024 trebuie citat.');
        self::assertStringContainsString('1016', $html, 'CPC art. 1016 (conținut cerere) trebuie referit.');
    }

    public function testRenderHtmlContainsPartiesAndCourt(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('SC PO Creditor SRL', $html);
        self::assertStringContainsString('SC PO Debtor SRL', $html);
        self::assertStringContainsString('Judecătoria Sector 1 București', $html);
    }

    public function testRenderHtmlContainsAmountsTable(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('10.000,00', $html, 'Sumă principală formatată RO');
        self::assertStringContainsString('850,00', $html, 'Dobânda acumulată');
        // Total creanță = 10000 + 850 = 10850 (taxa timbru afișată separat, NU în total — RON fix vs currency creanță)
        self::assertStringContainsString('10.850,00', $html);
        // Taxa timbru e ÎNTOTDEAUNA în RON (OUG 80/2013 art. 6 alin. 2), separată de currency creanță
        self::assertMatchesRegularExpression('/200,00\s+RON/', $html, 'Taxa timbru afișată explicit în RON.');
    }

    public function testGeneratePersistsDocumentWithTypeCerereOp(): void
    {
        $document = $this->service->generate($this->case);
        $this->em->flush();

        self::assertInstanceOf(Document::class, $document);
        self::assertSame(DocumentType::CERERE_OP, $document->getDocumentType());
        self::assertGreaterThan(0, $document->getFileSize());
        self::assertSame('application/pdf', $document->getMimeType());
        self::assertStringContainsString($this->case->getCaseNumber(), $document->getOriginalFilename());
    }

    public function testRenderHtmlWithNullCourtShowsPlaceholder(): void
    {
        $this->case->setCourt(null);
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('CERERE DE ORDONANȚĂ DE PLATĂ', $html, 'Heading rămâne afișat.');
        self::assertStringContainsString('instanță', $html, 'Placeholder text pentru court missing.');
    }
}
