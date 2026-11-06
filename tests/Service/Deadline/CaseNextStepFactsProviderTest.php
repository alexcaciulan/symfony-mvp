<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\Court;
use App\Entity\Document;
use App\Entity\FilingPackage;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Enum\DocumentType;
use App\Enum\FilingPackageStatus;
use App\Enum\OpisPlacement;
use App\Service\Case\CaseNextStepFacts;
use App\Service\Deadline\CaseNextStepFactsProvider;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The facts behind a row suggestion, read in bulk. Pinned because they replace, for the
 * agenda, what the case page reads one by one: a proof of another type or a superseded
 * package would make a row suggest an act the case page refuses.
 */
final class CaseNextStepFactsProviderTest extends KernelTestCase
{
    use CountyFixtureTrait;

    private EntityManagerInterface $em;
    private CaseNextStepFactsProvider $provider;
    private User $user;
    private ?Court $court = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->provider = static::getContainer()->get(CaseNextStepFactsProvider::class);

        $this->user = new User();
        $this->user->setEmail('next-step-facts-' . uniqid() . '@test.com');
        $this->user->setPassword('x');
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $id = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE s FROM filing_submission s JOIN legal_case c ON s.legal_case_id = c.id WHERE c.user_id = ?', [$id]);
        $conn->executeStatement('DELETE p FROM filing_package p JOIN legal_case c ON p.legal_case_id = c.id WHERE c.user_id = ?', [$id]);
        $conn->executeStatement('DELETE d FROM document d JOIN legal_case c ON d.legal_case_id = c.id WHERE c.user_id = ?', [$id]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$id]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$id]);
        if ($this->court?->getId() !== null) {
            $conn->executeStatement('DELETE FROM court WHERE id = ?', [$this->court->getId()]);
        }

        parent::tearDown();
    }

    public function testNoCasesNeedNoFacts(): void
    {
        self::assertSame([], $this->provider->forCases([]));
    }

    public function testACommunicationProofIsSeenOnASummonedCase(): void
    {
        $withProof = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $this->createDocument($withProof, DocumentType::DOVADA_COMUNICARE);
        $without = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();

        $facts = $this->provider->forCases([$withProof, $without]);

        self::assertTrue($this->facts($facts, $withProof)->hasCommunicationProof);
        self::assertFalse($this->facts($facts, $without)->hasCommunicationProof);
    }

    public function testAnotherDocumentIsNotTheProof(): void
    {
        $case = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $this->createDocument($case, DocumentType::FACTURA);
        $this->em->flush();

        self::assertFalse($this->facts($this->provider->forCases([$case]), $case)->hasCommunicationProof);
    }

    /** The proof is only asked about at the stage that needs it. */
    public function testTheProofOfACaseAtAnotherStageIsNotLoaded(): void
    {
        $case = $this->createCase(CaseStatus::AMIABIL);
        $this->createDocument($case, DocumentType::DOVADA_COMUNICARE);
        $this->em->flush();

        self::assertFalse($this->facts($this->provider->forCases([$case]), $case)->hasCommunicationProof);
    }

    public function testALivePackageIsAnEmailFiling(): void
    {
        $case = $this->createCase(CaseStatus::CERERE_GENERATA);
        $this->createPackage($case, FilingPackageStatus::BUILT);
        $without = $this->createCase(CaseStatus::CERERE_GENERATA);
        $this->em->flush();

        $facts = $this->provider->forCases([$case, $without]);

        self::assertTrue($this->facts($facts, $case)->hasEmailFiling);
        self::assertFalse($this->facts($facts, $without)->hasEmailFiling);
    }

    public function testASupersededPackageIsNotAnEmailFiling(): void
    {
        $case = $this->createCase(CaseStatus::CERERE_GENERATA);
        $this->createPackage($case, FilingPackageStatus::SUPERSEDED);
        $this->em->flush();

        self::assertFalse($this->facts($this->provider->forCases([$case]), $case)->hasEmailFiling);
    }

    /** A submission survives its package: once sent, the filing exists whatever the package. */
    public function testASubmissionIsAnEmailFilingEvenOverASupersededPackage(): void
    {
        $case = $this->createCase(CaseStatus::CERERE_GENERATA);
        $package = $this->createPackage($case, FilingPackageStatus::SUPERSEDED);
        $submission = new FilingSubmission($case, $package, $this->court(), $this->user, bin2hex(random_bytes(8)), 'v1', new \DateTimeImmutable());
        $submission->setAddresses('a@test.com', 'a@test.com', 'b@test.com', 'c@test.com', 'd@test.com', 'e@test.com');
        $this->em->persist($submission);
        $this->em->flush();

        self::assertTrue($this->facts($this->provider->forCases([$case]), $case)->hasEmailFiling);
    }

    public function testTheFilingPanelFollowsTheFullPayment(): void
    {
        $open = $this->createCase(CaseStatus::CERERE_GENERATA);
        $paid = $this->createCase(CaseStatus::CERERE_GENERATA);
        $paid->setFullPaymentDate(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $facts = $this->provider->forCases([$open, $paid]);

        self::assertTrue($this->facts($facts, $open)->filingPanelReady);
        self::assertFalse($this->facts($facts, $paid)->filingPanelReady);
    }

    public function testThePortalFactsStayFalse(): void
    {
        $case = $this->createCase(CaseStatus::CERERE_GENERATA);
        $this->em->flush();

        $facts = $this->facts($this->provider->forCases([$case]), $case);

        self::assertFalse($facts->lateBounceReview);
        self::assertFalse($facts->portalRulingProposal);
    }

    public function testACaseRepeatedInTheInputIsAnsweredOnce(): void
    {
        $case = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();

        self::assertCount(1, $this->provider->forCases([$case, $case, $case]));
    }

    /** @param array<int, CaseNextStepFacts> $facts */
    private function facts(array $facts, LegalCase $case): CaseNextStepFacts
    {
        return $facts[$case->getId()];
    }

    private function createCase(CaseStatus $status): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus($status);
        $this->em->persist($case);

        return $case;
    }

    private function createDocument(LegalCase $case, DocumentType $type): void
    {
        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType($type);
        $document->setOriginalFilename($type->value . '.pdf');
        $document->setStoredFilename('next-step-facts/' . uniqid() . '.pdf');
        $document->setFileSize(1024);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($this->user);
        $this->em->persist($document);
    }

    private function createPackage(LegalCase $case, FilingPackageStatus $status): FilingPackage
    {
        $package = new FilingPackage($case, 1, OpisPlacement::cases()[0], $this->user);
        $package->setSource(str_repeat('a', 64), []);
        $package->setStatus($status);
        $this->em->persist($package);

        return $package;
    }

    private function court(): Court
    {
        if ($this->court === null) {
            $this->court = new Court();
            $this->court->setName('Judecatoria Test ' . uniqid());
            $this->court->setType(CourtType::cases()[0]);
            $this->court->setCounty($this->createCounty($this->em));
            $this->em->persist($this->court);
        }

        return $this->court;
    }
}
