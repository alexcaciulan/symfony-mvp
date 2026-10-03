<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\LegalCaseDebtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\ContractualAccessoryLabel;
use App\Enum\DocumentType;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\Document\PaymentNoticeGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The payment notice follows the consulting lawyer's two texts, one per
 * accessory type. Critical legal assertion: the 15-day term (CPC art. 1.015)
 * is stated verbatim and the contractual 30 days of Law 72/2013 never appear.
 */
final class PaymentNoticeGeneratorServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PaymentNoticeGeneratorService $service;
    private User $user;
    private LegalCase $case;
    private Creditor $creditor;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(PaymentNoticeGeneratorService::class);

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('summons-gen-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Avocat');
        $this->user->setLastName('Test');
        $this->user->setBarNumber('B-12345');
        $this->em->persist($this->user);

        $creditor = $this->creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Test Creditor SRL');
        $creditor->setAddress('Str. Test 1, București');
        $creditor->setCui('RO99999111');
        $creditor->setIban('RO49AAAA1B31007593840000');
        $creditor->setBankName('Banca Transilvania');
        $this->em->persist($creditor);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setAmount('5000.00');
        $this->case->setCurrency('RON');
        $this->case->setRelationshipType(RelationshipType::COMERCIAL);
        $this->case->setCalculatedInterest('312.50');
        $this->em->persist($this->case);

        $debtor = new Debtor();
        $debtor->setUser($this->case->getUser());
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Test Debtor SRL');
        $debtor->setAddress('Strada Azurului, Nr. 25, Etaj 3, Ap. 20, cod poștal 061192');
        $debtor->setAddressCounty('București');
        $debtor->setAddressLocality('Sector 6');
        $debtor->setCui('RO88888222');
        $this->em->persist($debtor);
        $this->case->addDebtor(new LegalCaseDebtor($debtor));

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        parent::tearDown();
    }

    public function testRenderHtmlContains15DaysVerbatim(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('15 (cincisprezece) zile', $html, 'Somația trebuie să conțină termenul legal de 15 zile (CPC art. 1.015).');
    }

    public function testRenderHtmlContainsSomatieDePlataHeading(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('SOMAȚIE DE PLATĂ', $html);
    }

    public function testRenderHtmlDoesNotContain30Days(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('30 zile', $html, 'Somația NU trebuie să conțină termenul de 30 zile (Legea 72/2013, distinct de CPC).');
    }

    /**
     * The stored address is street level; the locality and the county live in
     * their own fields. The summons is served by post, so the rendered document
     * has to carry all three.
     */
    public function testRenderHtmlCarriesTheCompleteMailingAddress(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString(
            'Strada Azurului, Nr. 25, Etaj 3, Ap. 20, cod poștal 061192, Sector 6, București',
            $html,
        );
    }

    /** The lawyer's text signs as "Prin: / name / Avocat", without the bar roll number. */
    public function testSummonsNoLongerPrintsBarRollNumber(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('B-12345', $html);
        self::assertStringContainsString('<p>Prin:</p>', $html);
        self::assertStringContainsString('<p>Avocat</p>', $html);
    }

    public function testRenderHtmlContainsCreditorAndDebtorNames(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('SC Test Creditor SRL', $html);
        self::assertStringContainsString('SC Test Debtor SRL', $html);
        self::assertStringContainsString('5.000,00', $html, 'Suma principală trebuie formatată cu separator de mii (5.000,00).');
    }

    public function testGeneratePersistsDocumentWithTypeSomatie(): void
    {
        $document = $this->service->generate($this->case);
        $this->em->flush();

        self::assertInstanceOf(Document::class, $document);
        self::assertSame(DocumentType::SOMATIE, $document->getDocumentType());
        self::assertSame($this->case, $document->getLegalCase());
        self::assertGreaterThan(0, $document->getFileSize());
        self::assertSame('application/pdf', $document->getMimeType());
        self::assertStringContainsString($this->case->getCaseNumber(), $document->getOriginalFilename());
        self::assertNotNull($document->getId(), 'Document should be persisted with an ID after flush.');
    }

    public function testRenderHtmlShowsContractObjectWhenSet(): void
    {
        $this->case->setContractObject('prestarea de servicii de transport');

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('raporturi contractuale având ca obiect prestarea de servicii de transport, în temeiul', $html);
        self::assertStringContainsString('obligațiile asumate, respectiv prestarea de servicii de transport, astfel cum', $html);
    }

    public function testMissingContractObjectDropsTheObjectClause(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('având ca obiect', $html);
        self::assertStringContainsString('raporturi contractuale, în temeiul facturilor fiscale emise.', $html);
    }

    public function testNamedContractIsQuotedAsTheBasisOfTheRelationship(): void
    {
        $this->case->setContractNumber('12');
        $this->case->setContractDate(new \DateTime('2024-02-01'));

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('în temeiul Contractului nr. 12 din data de 01.02.2024 și al facturilor fiscale emise.', $html);
        self::assertStringContainsString('astfel cum rezultă din Contractul nr. 12 din data de 01.02.2024 și facturile fiscale emise.', $html);
    }

    public function testRenderHtmlHandlesNullInterestWithoutCrashing(): void
    {
        $this->case->setCalculatedInterest(null);
        $this->case->setDueDate(null);
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('15 (cincisprezece) zile', $html);
        self::assertStringContainsString('5.000,00', $html, 'Fără dobândă, totalul rămâne principalul.');
    }

    public function testRenderHtmlContainsAllStructuralSections(): void
    {
        $html = $this->service->renderHtml($this->case);

        foreach ([
            'CĂTRE:',
            'DE LA:',
            'Subiect: Somație de plată',
            'Stimate Domn / Stimată Doamnă,',
            'I. CU PRIVIRE LA OBLIGAȚIA DE PLATĂ A DEBITULUI PRINCIPAL',
            'II. CU PRIVIRE LA DOBÂNDA LEGALĂ PENALIZATOARE',
            'III. CU PRIVIRE LA IMPUTAȚIA PLĂȚII',
            'VĂ SOMĂM',
            'art. 1.014 și următoarele din Codul de procedură civilă',
            'în temeiul art. 1.015 din Codul de procedură civilă,',
            'art. 158 din Codul de procedură civilă',
            'art. 1.507 din Codul civil',
            'Cu deosebită considerație,',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
    }

    /** The lawyer's text cites the payment order procedure from art. 1.014. */
    public function testCitesArticle1014NotArticle1013(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('1013', $html);
        self::assertStringNotContainsString('1.013', $html);
    }

    public function testLegalPenaltyRendersSevenColumnInterestTable(): void
    {
        // Test-DB rate fixtures: 6,50% valid from 2025-01-01 (no change until 2025-08-01),
        // so the period 2025-01-01..2025-04-01 is a single 14,50% (BNR 6,5 + 8) block.
        $this->case->setPenaltyType(PenaltyType::LEGAL_PENALIZATOARE);
        $this->case->setAmount('100000.00');
        $this->case->setInvoiceNumber('FCT-7');
        $this->case->setInvoiceDate(new \DateTime('2024-12-02'));
        $this->case->setDueDate(new \DateTime('2025-01-01'));
        $this->case->setPaymentNoticeDate(new \DateTime('2025-04-01')); // 90 days, rate 14.5%
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        foreach (['Factură/document', 'Sold asupra căruia se calculează dobânda', 'Perioadă', 'Număr zile', 'Rata BNR', 'Rata dobânzii legale penalizatoare', 'Dobândă calculată'] as $column) {
            self::assertStringContainsString($column, $html);
        }
        self::assertStringContainsString('FCT-7 din 02.12.2024', $html);
        // The first day that accrues is the day after the due date.
        self::assertStringContainsString('02.01.2025 – 01.04.2025', $html);
        self::assertStringContainsString('6,50%', $html);
        self::assertStringContainsString('14,50%', $html);
        // 100000 * 14.5% * 90 / 365 = 3575.34
        self::assertStringContainsString('TOTAL DOBÂNDĂ LEGALĂ PENALIZATOARE LA DATA DE 01.04.2025: 3.575,34 LEI', $html);
        self::assertStringContainsString('art. 3 alin. (2¹) din OG nr. 13/2011', $html);
        self::assertStringNotContainsString('2^1', $html);
    }

    public function testContractualPenaltyRendersSevenColumnTableAndQuotesTheClause(): void
    {
        $this->case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $this->case->setAmount('175525.00');
        $this->case->setContractualPenaltyRate('0.100');
        $this->case->setPenaltyClauseArticle('art. 7.2');
        $this->case->setPenaltyClauseText('Întârzierea la plată atrage penalități de 0,1% pe zi.');
        $this->case->setContractNumber('12');
        $due = new \DateTime('2025-02-18');
        $this->case->setDueDate($due);
        $this->case->setPaymentNoticeDate((clone $due)->modify('+51 days'));
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('II. CU PRIVIRE LA DOBÂNDA PENALIZATOARE / PENALITĂȚILE CONTRACTUALE', $html);
        self::assertStringContainsString('Potrivit art. 7.2 din Contractul nr. 12, Părțile au convenit următoarele:', $html);
        self::assertStringContainsString('Întârzierea la plată atrage penalități de 0,1% pe zi.', $html);
        self::assertStringContainsString('plata unor penalități de întârziere în cuantum de 0,1% pe zi', $html);
        self::assertStringContainsString('începând cu data de 19.02.2025', $html);
        foreach (['Sold (LEI)', 'Scadență', 'Perioada de întârziere', 'Nr. zile', 'Rata contractuală', 'Penalități calculate'] as $column) {
            self::assertStringContainsString($column, $html);
        }
        self::assertStringContainsString('18.02.2025', $html);
        self::assertStringContainsString('19.02.2025 – 10.04.2025', $html);
        self::assertStringContainsString('0,1% / zi', $html);
        // 175525 * 0.10% * 51 = 8951.78
        self::assertStringContainsString('8.951,78', $html);
        self::assertStringContainsString('art. 1.538 din Codul civil', $html);
        self::assertStringContainsString('art. 1.539 din Codul civil', $html);
        self::assertStringContainsString('ulterior asupra dobânzilor și penalităților datorate', $html);
    }

    public function testContractualFallbackSentenceWhenClauseTextMissing(): void
    {
        $this->case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $this->case->setContractualPenaltyRate('0.100');
        $this->case->setDueDate(new \DateTime('2025-02-18'));
        $this->case->setPaymentNoticeDate(new \DateTime('2025-04-10'));

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('Părțile au convenit, potrivit clauzei contractuale din contractul încheiat între părți, un mecanism de penalizare pentru întârzierea la plată.', $html);
        self::assertStringNotContainsString('Părțile au convenit următoarele:', $html);
    }

    public function testAccessoryLabelIsUsedThroughoutAndUppercasedOnTotalLine(): void
    {
        $this->case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $this->case->setContractualAccessoryLabel(ContractualAccessoryLabel::MAJORARI_INTARZIERE);
        $this->case->setContractualPenaltyRate('0.100');
        $this->case->setDueDate(new \DateTime('2025-02-18'));
        $this->case->setPaymentNoticeDate(new \DateTime('2025-04-10'));

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('TOTAL MAJORĂRI DE ÎNTÂRZIERE LA DATA DE 10.04.2025: 255,00 LEI', $html);
        self::assertStringContainsString('reprezentând majorări de întârziere calculate până la data de 10.04.2025', $html);
        self::assertStringContainsString('la care se adaugă majorări de întârziere calculate în continuare', $html);
        self::assertStringNotContainsString('penalități de întârziere', $html);
        // The heading of section II is fixed, whatever the label.
        self::assertStringContainsString('II. CU PRIVIRE LA DOBÂNDA PENALIZATOARE / PENALITĂȚILE CONTRACTUALE', $html);
    }

    public function testLegalSummonsNeverMentionsContractualWording(): void
    {
        $this->case->setContractualAccessoryLabel(ContractualAccessoryLabel::MAJORARI_INTARZIERE);

        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('majorări de întârziere', $html);
        self::assertStringNotContainsString('accesoriile contractuale', $html);
        self::assertStringContainsString('ulterior asupra dobânzilor datorate și', $html);
    }

    public function testSummonsOmitsLegalCostsSection(): void
    {
        $this->case->setLegalCostsFixed('250.00');
        $this->case->setLegalCostsCurrency('EUR');

        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('1531', $html);
        self::assertStringNotContainsString('250,00 EUR', $html);
    }

    public function testIbanAppearsOnlyInThePaymentSentence(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('Plata va fi efectuată în contul RO49AAAA1B31007593840000, deschis la Banca Transilvania, titular SC Test Creditor SRL.', $html);
        self::assertSame(1, substr_count($html, 'RO49AAAA1B31007593840000'));
    }

    public function testPaymentSentenceDoesNotDoubleTheFullStopAfterAnAbbreviatedName(): void
    {
        $this->creditor->setName('BLUEBOX MEDICAL S.R.L.');

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('titular BLUEBOX MEDICAL S.R.L.</p>', preg_replace('/\s+</', '<', $html));
        self::assertStringNotContainsString('S.R.L..', $html);
    }

    public function testPaymentSentenceIsOmittedWithoutIban(): void
    {
        $this->creditor->setIban(null);

        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('Plata va fi efectuată', $html);
    }

    public function testPaymentSentenceOmitsBankWhenBankNameMissing(): void
    {
        $this->creditor->setBankName(null);

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('Plata va fi efectuată în contul RO49AAAA1B31007593840000, titular SC Test Creditor SRL.', $html);
    }

    public function testRedLawyerNotesAndPlaceholdersNeverReachThePdf(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('de pus', $html);
        self::assertStringNotContainsString('{{', $html);
        self::assertDoesNotMatchRegularExpression('/%[a-z_]+%/', $html, 'A translation parameter was left unreplaced.');
    }

    public function testNoticeNumberIsPrintedWithTheDate(): void
    {
        $this->case->setPaymentNoticeNumber('868');
        $this->case->setPaymentNoticeDate(new \DateTime('2025-04-10'));

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('Nr. 868 / 10.04.2025', $html);
    }

    public function testWithoutNoticeNumberOnlyTheDateIsPrinted(): void
    {
        $this->case->setPaymentNoticeDate(new \DateTime('2025-04-10'));

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('Data: 10.04.2025', $html);
        self::assertStringNotContainsString('Nr.  /', $html);
    }

    public function testCreditorRoleIsNeverHardcodedAsAdministrator(): void
    {
        $this->creditor->setLegalRepresentative('Ion Popescu');

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('legal reprezentată prin Ion Popescu, prin Avocat Test, în calitate de reprezentant convențional', $html);
        self::assertStringNotContainsString('Administrator', $html);
    }

    public function testPfCreditorNeverClaimsLegalEntityStatus(): void
    {
        $this->creditor->setPersonType(PersonType::PF);
        $this->creditor->setName('Ion Popescu');

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('Subscrisa, Ion Popescu, cu domiciliul în', $html);
        self::assertStringContainsString('Domiciliul:', $html);
        self::assertStringNotContainsString('persoană juridică', $html);
        self::assertStringNotContainsString('înregistrată la Registrul Comerțului', $html);
    }

    public function testAttentionLineComesFromTheDebtorAdministrator(): void
    {
        $this->case->getPrimaryDebtor()->getDebtor()->setAdministrator('Maria Ionescu');

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('În atenția: Maria Ionescu', $html);
    }

    public function testAddressForServiceUsesTheLawyerProfile(): void
    {
        $this->user->setStreet('Str. Avocaților');
        $this->user->setStreetNumber('3');
        $this->user->setCity('Cluj-Napoca');
        $this->user->setPhone('0722000000');

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('pentru comunicarea actelor de procedură la adresa Str. Avocaților nr. 3, Cluj-Napoca, persoană desemnată pentru primirea corespondenței Avocat Test, e-mail ' . $this->user->getEmail() . ', telefon 0722000000,', $html);
    }

    public function testMissingAddressAndPhoneDropTheirFragments(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('pentru comunicarea actelor de procedură, persoană desemnată pentru primirea corespondenței Avocat Test', $html);
        self::assertStringNotContainsString('la adresa', $html);
        self::assertStringNotContainsString('telefon', $html);
    }

    public function testSummonsIsRenderedInRomanianEvenForEnglishUser(): void
    {
        $switcher = static::getContainer()->get(LocaleSwitcher::class);
        $switcher->setLocale('en');

        try {
            $html = $this->service->renderHtml($this->case);
        } finally {
            $switcher->reset();
        }

        self::assertStringContainsString('VĂ SOMĂM', $html);
        self::assertStringNotContainsString('WE HEREBY SUMMON YOU', $html);
    }
}
