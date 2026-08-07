<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\ClaimItem;
use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\ClaimItemKind;
use App\Enum\CourtType;
use App\Enum\DocumentType;
use App\Enum\PersonType;
use App\Enum\StampDutyStatus;
use App\Service\Document\PaymentOrderRequestGeneratorService;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 5.2 — Tests for PaymentOrderRequestGeneratorService.
 *
 * Critical legal assertions: CPC art. 1013-1024 + raport COMERCIAL + numele
 * instanței obligatorii. Template trebuie să afișeze toate elementele cerute
 * de art. 1016 (instanță, părți, sume, temei, anexe).
 */
final class PaymentOrderRequestGeneratorServiceTest extends KernelTestCase
{
    use CountyFixtureTrait;

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
        $court->setCounty($this->createCounty($this->em, 'București'));
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
        $conn->executeStatement('DELETE ci FROM claim_item ci JOIN legal_case lc ON ci.legal_case_id = lc.id WHERE lc.user_id = ?', [$userId]);
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
        self::assertStringContainsString('1013-1024', $html, 'Temeiul legal CPC art. 1013-1024 trebuie citat.');
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

    /**
     * The petition asks the court for the costs (CPC art. 453 is not applied ex
     * officio), so the stamp duty must be claimed back from the debtor. Without this
     * the creditor wins and still loses the 200 lei.
     */
    public function testRenderHtmlClaimsTheStampDutyAsRecoverableCosts(): void
    {
        $this->case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $this->attachStampDutyProof();

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('art. 453', $html);
        self::assertStringContainsString('achitată conform dovezii anexate', $html);
    }

    /**
     * Payment through the electronic registry reaches the court on its own channel,
     * so the case reads as paid while no proof sits in our package. Invoking an annex
     * that is not there would be contradicted by the very package filed.
     */
    public function testRenderHtmlDoesNotInvokeAnAnnexWhenThePaymentWentThroughTheRegistry(): void
    {
        $this->case->setStampDutyStatus(StampDutyStatus::ACHITATA);

        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('achitată conform dovezii anexate', $html);
        self::assertStringContainsString('registratura electronică', $html);
    }

    /**
     * The recommended channel takes the duty and the petition in one step, so payment
     * lands after the package is built. Saying the duty will be paid during
     * regularization there would misstate what the lawyer is actually doing.
     */
    public function testRenderHtmlStatesThatTheDutyIsPaidAtFilingWhenThatWasChosen(): void
    {
        $this->case->setStampDutyStatus(StampDutyStatus::ACHITARE_LA_DEPUNERE);

        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('achitată conform dovezii anexate', $html);
        self::assertStringNotContainsString('art. 33 alin. 2', $html);
        self::assertStringContainsString('se achită odată cu înregistrarea', $html);
    }

    /**
     * The petition is what fixes where the court sends everything afterwards. Without
     * both the elected domicile and the person designated to receive documents, the
     * mention does not redirect service, so it is written as one sentence or not at all.
     */
    public function testRenderHtmlStatesTheElectedProceduralDomicileAtTheLawyerOffice(): void
    {
        $this->user->setFirstName('Ion');
        $this->user->setLastName('Popescu');
        $this->user->setStreet('Str. Avocatilor');
        $this->user->setStreetNumber('12');
        $this->user->setCity('Cluj-Napoca');
        $this->user->setCounty('Cluj');
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('domiciliul procedural ales', $html);
        self::assertStringContainsString('Str. Avocatilor nr. 12, Cluj-Napoca, Cluj', $html);
        self::assertStringContainsString('persoana însărcinată cu primirea actelor', $html);
    }

    /**
     * A truncated address on an act the judge reads is worse than none: it claims to
     * be where communications should go.
     */
    public function testRenderHtmlOmitsTheElectedDomicileWhenTheOfficeAddressIsIncomplete(): void
    {
        $this->user->setFirstName('Ion');
        $this->user->setLastName('Popescu');
        $this->user->setStreet('Str. Avocatilor');
        $this->user->setCity('Cluj-Napoca');
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('domiciliul procedural ales', $html);
    }

    private function attachStampDutyProof(): void
    {
        $proof = new Document();
        $proof->setLegalCase($this->case);
        $proof->setDocumentType(DocumentType::DOVADA_TAXA_TIMBRU);
        $proof->setOriginalFilename('dovada.pdf');
        $proof->setStoredFilename('cases/test/dovada.pdf');
        $proof->setFileSize(100);
        $proof->setMimeType('application/pdf');
        $proof->setUploadedBy($this->user);
        $this->em->persist($proof);
        $this->case->addDocument($proof);
        $this->em->flush();
    }

    /**
     * The case can be filed unstamped, with the duty paid during regularization (OUG
     * 80/2013 art. 33 alin. 2). Claiming "paid as per the attached proof" there would
     * assert a falsehood to the judge, contradicted by the very package we produce:
     * no proof is in the ZIP.
     */
    public function testRenderHtmlDoesNotClaimAProofThatWasNeverFiled(): void
    {
        $this->case->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);

        $html = $this->service->renderHtml($this->case);

        // Every phrasing that ties the duty to an attached proof, not just the one
        // sentence a review happened to flag: the first fix patched the costs section
        // while the amounts table went on labelling the duty "(anexată)" a line above.
        self::assertStringNotContainsString('achitată conform dovezii anexate', $html);
        self::assertStringNotContainsString('Taxă timbru (anexată)', $html, 'The amounts table must not claim a proof either.');
        self::assertStringNotContainsString('(anexată)', $html, 'No label may assert an attachment that is not in the package.');
        self::assertStringContainsString('urmând a fi achitată', $html);
        // The amount is still claimed: it will be owed either way.
        self::assertMatchesRegularExpression('/200,00\s+RON/', $html);
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

    /**
     * The object of the claim (LegalCase::claimDescription), when the lawyer
     * stated it, must appear on the petition so the court reads what is claimed
     * for, not only the invoice figures.
     */
    public function testRenderHtmlShowsClaimObjectWhenDescriptionSet(): void
    {
        $this->case->setClaimDescription('Contravaloare servicii de consultanță IT');
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('Contravaloare servicii de consultanță IT', $html);
    }

    public function testRenderHtmlOmitsClaimObjectRowWhenDescriptionEmpty(): void
    {
        $this->case->setClaimDescription(null);
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('Obiectul creanței', $html);
    }

    /**
     * The art. 99 note declares the object value as the sum of all heads on the
     * premise of a single legal relationship. The resolver groups per cause and
     * returns a court even when distinct causes fall to the same court type, so
     * on two separate contracts the note would misstate the object value. It must
     * be suppressed unless every position rests on the same cause.
     */
    public function testArt99NoteSuppressedWhenPositionsRestOnDifferentCauses(): void
    {
        $this->addClaimItem('FACT-1', '5000.00', 'Contract 10/2024');
        $this->addClaimItem('FACT-2', '5000.00', 'Contract 99/2024');
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('același raport juridic', $html);
    }

    public function testArt99NotePresentWhenAllPositionsShareOneCause(): void
    {
        $this->addClaimItem('FACT-1', '5000.00', 'Contract 10/2024');
        $this->addClaimItem('FACT-2', '5000.00', 'Contract 10/2024');
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('același raport juridic', $html);
    }

    /**
     * A position that states no cause joins the file's single stated cause, so a
     * labelled invoice plus an unlabelled one is one cause, not two. This is the
     * exact grouping the competent-court resolver uses to route the file, so the
     * note that justifies the cumulative object value must follow it: suppressing
     * the note here would leave the tribunal with a cumulated total and no art. 99
     * justification, inviting the plea of material incompetence.
     */
    public function testArt99NotePresentWhenUnlabelledPositionJoinsSingleStatedCause(): void
    {
        $this->addClaimItem('FACT-1', '5000.00', 'Contract 10/2024');
        $this->addClaimItem('FACT-2', '5000.00', null);
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('același raport juridic', $html);
    }

    /**
     * With a counting position the interest asked of the court is recomputed as
     * of the notice date, not read from the frozen calculatedInterest written at
     * wizard submit. The frozen value must not surface on the petition once a
     * position drives the accessory, or the rows and the total would disagree.
     */
    public function testRenderHtmlUsesRecalculatedInterestNotFrozenFieldForSinglePosition(): void
    {
        // A value the recomputed accessory could never equal, so its total
        // absence proves the petition ignored the frozen field.
        $this->case->setCalculatedInterest('77777.00');
        $this->addClaimItem('FACT-1', '10000.00', 'Contract 10/2024');
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('77.777,00', $html, 'The frozen calculatedInterest must not drive the petition once a position exists.');
        self::assertStringContainsString('Dobândă acumulată', $html, 'The recalculated interest row is still shown.');
    }

    private function addClaimItem(string $documentNumber, string $amountRon, ?string $causeReference): ClaimItem
    {
        $item = new ClaimItem();
        $item->setLegalCase($this->case);
        $item->setKind(ClaimItemKind::INVOICE);
        $item->setDocumentNumber($documentNumber);
        $item->setDueDate(new \DateTimeImmutable('2024-06-15'));
        $item->setAmount($amountRon);
        $item->setCurrency('RON');
        $item->setAmountRon($amountRon);
        $item->setCauseReference($causeReference);
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('dedup-' . uniqid());
        $this->em->persist($item);
        $this->case->addClaimItem($item);

        return $item;
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
