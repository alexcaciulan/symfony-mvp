<?php

namespace App\Tests\Service\Portal;

use App\Entity\Court;
use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtType;
use App\Enum\PortalEventType;
use App\Service\Portal\PortalEventDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PortalEventDetectorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PortalEventDetector $detector;
    private User $user;
    private Court $court;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->detector = static::getContainer()->get(PortalEventDetector::class);
        $this->testPrefix = 'portal-det-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);

        $this->court = new Court();
        $this->court->setName('Test Court ' . $this->testPrefix);
        $this->court->setCounty('CJ');
        $this->court->setType(CourtType::JUDECATORIE);
        $this->court->setPortalCode('TestCourt' . uniqid());
        $this->em->persist($this->court);

        $this->em->flush();
    }

    private function createCase(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCourt($this->court);
        $case->setStatus('submitted_to_court');
        $case->setCaseNumber('100/211/2026');
        $case->setCurrentStep(6);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    public function testDetectsNewHearingScheduled(): void
    {
        $case = $this->createCase();

        $dosarData = [
            'sedinte' => [
                ['data' => '15.03.2026', 'complet' => 'C5', 'ora' => '09:00', 'solutie' => null, 'solutieSumar' => null, 'dataPronuntare' => null],
            ],
            'caiAtac' => [],
        ];

        $events = $this->detector->detectNewEvents($case, $dosarData);

        $this->assertCount(1, $events);
        $this->assertSame(PortalEventType::HEARING_SCHEDULED, $events[0]['type']);
        $this->assertSame('2026-03-15', $events[0]['eventDate']->format('Y-m-d'));
        $this->assertStringContainsString('Termen de judecată fixat', $events[0]['description']);
    }

    public function testDetectsHearingCompleted(): void
    {
        $case = $this->createCase();

        $dosarData = [
            'sedinte' => [
                [
                    'data' => '10.02.2026',
                    'complet' => 'C1',
                    'ora' => '10:00',
                    'solutie' => 'Admite cererea',
                    'solutieSumar' => 'Admis',
                    'dataPronuntare' => '10.02.2026',
                ],
            ],
            'caiAtac' => [],
        ];

        $events = $this->detector->detectNewEvents($case, $dosarData);

        $this->assertCount(1, $events);
        $this->assertSame(PortalEventType::HEARING_COMPLETED, $events[0]['type']);
        $this->assertSame('Admite cererea', $events[0]['solutie']);
        $this->assertSame('Admis', $events[0]['solutieSumar']);
    }

    public function testDetectsAppealFiled(): void
    {
        $case = $this->createCase();

        $dosarData = [
            'sedinte' => [],
            'caiAtac' => [
                ['dataDeclarare' => '20.02.2026', 'parteDeclaratoare' => 'SC Test SRL', 'tipCaleAtac' => 'Apel'],
            ],
        ];

        $events = $this->detector->detectNewEvents($case, $dosarData);

        $this->assertCount(1, $events);
        $this->assertSame(PortalEventType::APPEAL_FILED, $events[0]['type']);
        $this->assertStringContainsString('Apel', $events[0]['description']);
        $this->assertStringContainsString('SC Test SRL', $events[0]['description']);
    }

    public function testDeduplicatesExistingEvents(): void
    {
        $case = $this->createCase();

        // Pre-create an event for the hearing
        $existing = new CourtPortalEvent();
        $existing->setLegalCase($case);
        $existing->setEventType(PortalEventType::HEARING_SCHEDULED);
        $existing->setEventDate(new \DateTime('2026-03-15'));
        $existing->setDescription('Already detected');
        $this->em->persist($existing);
        $this->em->flush();

        $dosarData = [
            'sedinte' => [
                ['data' => '15.03.2026', 'complet' => 'C5', 'ora' => '09:00', 'solutie' => null, 'solutieSumar' => null, 'dataPronuntare' => null],
            ],
            'caiAtac' => [],
        ];

        $events = $this->detector->detectNewEvents($case, $dosarData);

        $this->assertCount(0, $events, 'Should not detect already existing event');
    }

    public function testDetectsMultipleNewEvents(): void
    {
        $case = $this->createCase();

        $dosarData = [
            'sedinte' => [
                ['data' => '15.03.2026', 'complet' => 'C5', 'ora' => '09:00', 'solutie' => null, 'solutieSumar' => null, 'dataPronuntare' => null],
                ['data' => '10.02.2026', 'complet' => 'C1', 'ora' => '10:00', 'solutie' => 'Admis', 'solutieSumar' => 'Admis', 'dataPronuntare' => '10.02.2026'],
            ],
            'caiAtac' => [
                ['dataDeclarare' => '20.02.2026', 'parteDeclaratoare' => 'Test', 'tipCaleAtac' => 'Apel'],
            ],
        ];

        $events = $this->detector->detectNewEvents($case, $dosarData);

        $this->assertCount(3, $events);

        $types = array_map(fn($e) => $e['type'], $events);
        $this->assertContains(PortalEventType::HEARING_SCHEDULED, $types);
        $this->assertContains(PortalEventType::HEARING_COMPLETED, $types);
        $this->assertContains(PortalEventType::APPEAL_FILED, $types);
    }

    public function testDetectsCaseInfoUpdate(): void
    {
        $case = $this->createCase();

        $dosarData = [
            'sedinte' => [],
            'caiAtac' => [],
            'stadiuProcesual' => 'Fond',
        ];

        $events = $this->detector->detectNewEvents($case, $dosarData);

        $this->assertCount(1, $events);
        $this->assertSame(PortalEventType::CASE_INFO_UPDATE, $events[0]['type']);
        $this->assertStringContainsString('Fond', $events[0]['description']);
    }

    public function testHandlesEmptyDosarData(): void
    {
        $case = $this->createCase();

        $events = $this->detector->detectNewEvents($case, []);

        $this->assertCount(0, $events);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE cpe FROM court_portal_event cpe JOIN legal_case lc ON cpe.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE ?", ['Test Court ' . $this->testPrefix . '%']);
        parent::tearDown();
    }
}
