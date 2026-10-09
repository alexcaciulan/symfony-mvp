<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\CaseStatusHistory;
use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Enum\NotificationType;
use App\Enum\PersonType;
use App\Enum\PortalCaseMatchSource;
use App\Service\Portal\PortalJustClient;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The lawyer review asked for the filed case to be found on the portal without
 * the lawyer having to look, with the lawyer still confirming it before
 * monitoring starts. Found means notified; activating stays theirs.
 */
final class PortalDiscoverCasesCommandTest extends KernelTestCase
{
    use CountyFixtureTrait;

    private EntityManagerInterface $em;
    private string $prefix;
    /** @var list<int> */
    private array $courtIds = [];
    /** The fake portal: its listing can change between runs, and it counts searches. */
    private PortalJustClient $portal;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'portal-discover-' . uniqid();
        $this->portal = $this->portalListing();
        static::getContainer()->set(PortalJustClient::class, $this->portal);
    }

    public function testAFiledCaseFoundOnThePortalIsAnnouncedOnceAndNotActivated(): void
    {
        $case = $this->filedCase(CaseStatus::CERERE_DEPUSA);

        $this->discover();
        $this->discover();

        $notifications = $this->em->getRepository(Notification::class)->findBy(['legalCase' => $case, 'type' => NotificationType::PORTAL_CASE_FOUND]);
        self::assertCount(1, $notifications, 'the same number is announced once');
        self::assertStringContainsString('19883/211/2025', $notifications[0]->getMessage());
        self::assertStringContainsString('tab=portal', (string) $notifications[0]->getResourceLink());

        $this->em->refresh($case);
        self::assertNull($case->getCourtCaseNumber(), 'the lawyer confirms the match, the cron does not');
        self::assertFalse($case->isPortalMonitoringActive());
    }

    public function testTheNumberFoundIsKeptOnTheCaseAsAProposal(): void
    {
        $case = $this->filedCase(CaseStatus::CERERE_DEPUSA);

        $this->discover();

        $this->em->refresh($case);
        self::assertSame('19883/211/2025', $case->getPortalProposedNumber());
        self::assertNull($case->getCourtCaseNumber(), 'a proposal, not the confirmed number');
    }

    public function testAProposalSetAsideIsNotProposedAgainEvenOnceItsNotificationIsGone(): void
    {
        $case = $this->filedCase(CaseStatus::CERERE_DEPUSA);
        $this->discover();
        $this->em->refresh($case);
        $case->dismissPortalProposal(null);
        $this->em->flush();
        // Notifications are pruned after a while; the decision must outlive them.
        $this->em->getConnection()->executeStatement('DELETE FROM notification WHERE legal_case_id = ?', [$case->getId()]);

        $this->discover();

        $this->em->refresh($case);
        self::assertNull($case->getPortalProposedNumber());
        self::assertSame([], $this->em->getRepository(Notification::class)->findBy(['legalCase' => $case]), 'not announced again');
    }

    public function testOnceTheOldCaseIsSetAsideTheNewOneIsProposed(): void
    {
        // The same parties had an earlier payment order, without its registration
        // date (an older portal record): proposed while ours is not on the portal,
        // the lawyer sets it aside, and ours is proposed once it appears.
        $this->portal->listing = [$this->dosar('100/211/2024', null)];
        $case = $this->filedCase(CaseStatus::CERERE_DEPUSA);
        $this->discover();
        $this->em->refresh($case);
        self::assertSame('100/211/2024', $case->getPortalProposedNumber());
        $case->dismissPortalProposal(null);
        $this->em->flush();

        $this->portal->listing = [$this->dosar('100/211/2024', '2024-05-01'), $this->dosar('19883/211/2025', (new \DateTimeImmutable())->format('Y-m-d'))];
        $this->discover();

        $this->em->refresh($case);
        self::assertSame('19883/211/2025', $case->getPortalProposedNumber());
    }

    public function testACaseWithAProposalWaitingIsNotSearchedAgain(): void
    {
        $case = $this->filedCase(CaseStatus::CERERE_DEPUSA);
        $case->proposePortalMatch('19883/211/2025', PortalCaseMatchSource::MANUAL);
        $this->em->flush();

        $this->discover();

        self::assertSame(0, $this->portal->searches, 'the lawyer has a proposal to decide on, the portal is not asked again');
    }

    public function testAnEarlierCaseOfTheSamePartiesIsNotProposed(): void
    {
        $this->portal->listing = [$this->dosar('100/211/2024', '2024-05-01')];
        $case = $this->filedCase(CaseStatus::CERERE_DEPUSA);

        $this->discover();

        $this->em->refresh($case);
        self::assertNull($case->getPortalProposedNumber(), 'registered before our request was generated');
        self::assertSame([], $this->em->getRepository(Notification::class)->findBy(['legalCase' => $case]));
    }

    public function testACaseNotYetFiledIsNotSearched(): void
    {
        $case = $this->filedCase(CaseStatus::CERERE_GENERATA);

        $this->discover();

        self::assertSame([], $this->em->getRepository(Notification::class)->findBy(['legalCase' => $case]));
    }

    private function discover(): void
    {
        $command = (new Application(self::$kernel))->find('app:portal-discover-cases');
        (new CommandTester($command))->execute([]);
    }

    private function portalListing(): PortalJustClient
    {
        return new class([$this->dosar('19883/211/2025', (new \DateTimeImmutable())->format('Y-m-d'))]) extends PortalJustClient {
            public int $searches = 0;

            /** @param list<array<string, mixed>> $listing */
            public function __construct(public array $listing)
            {
                parent::__construct(new NullLogger());
            }

            public function searchByParty(string $partyName, string $institutionCode, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
            {
                ++$this->searches;

                return $partyName === 'HEALTHU WORLDWIDE' ? $this->listing : [];
            }
        };
    }

    /** @return array<string, mixed> */
    private function dosar(string $number, ?string $registered): array
    {
        return [
            'numar' => $number,
            'data' => $registered,
            'institutie' => 'JudecatoriaCLUJNAPOCA',
            'departament' => 'Civil',
            'categorieCaz' => null,
            'stadiuProcesual' => 'Fond',
            'obiect' => 'ordonanță de plată',
            'dataModificare' => $registered,
            'parti' => [
                ['nume' => 'EXPERT SERVICE SUPPLY SRL', 'calitateParte' => 'Creditor'],
                ['nume' => 'HEALTHU WORLDWIDE SRL', 'calitateParte' => 'Debitor'],
            ],
            'sedinte' => [],
            'caiAtac' => [],
        ];
    }

    private function filedCase(CaseStatus $status): LegalCase
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($this->prefix . '-' . uniqid() . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'test'));
        $user->setIsVerified(true);
        $this->em->persist($user);

        $court = new Court();
        $court->setName('Discover Court ' . $this->prefix . '-' . uniqid());
        $court->setType(CourtType::JUDECATORIE);
        $court->setCounty($this->createCounty($this->em, 'CJ'));
        $court->setPortalCode('JudecatoriaCLUJNAPOCA' . uniqid());
        $this->em->persist($court);

        $creditor = new Creditor();
        $creditor->setUser($user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('EXPERT SERVICE SUPPLY S.R.L.');
        $creditor->setAddress('Str. Preciziei 24A');
        $this->em->persist($creditor);

        $debtor = new Debtor();
        $debtor->setUser($user);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('HEALTHU WORLDWIDE S.R.L.');
        $debtor->setAddress('Str. Bihorului 10');
        $this->em->persist($debtor);

        $case = new LegalCase();
        $case->setUser($user);
        $case->setAmount('27340.00');
        $case->setCurrency('RON');
        $case->setStatus($status);
        $case->setCourt($court);
        $case->setCreditor($creditor);
        $case->addDebtor(new LegalCaseDebtor($debtor));
        $this->em->persist($case);
        $generated = new CaseStatusHistory();
        $generated->setLegalCase($case);
        $generated->setOldStatus(CaseStatus::SOMATIE_TRIMISA->value);
        $generated->setNewStatus(CaseStatus::CERERE_GENERATA->value);
        $case->getStatusHistory()->add($generated);
        $this->em->persist($generated);
        $this->em->flush();
        $this->courtIds[] = (int) $court->getId();

        return $case;
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $users = 'SELECT id FROM user WHERE email LIKE ' . $conn->quote($this->prefix . '%');
        $conn->executeStatement("DELETE FROM notification WHERE user_id IN ($users)");
        $conn->executeStatement("DELETE h FROM case_status_history h JOIN legal_case lc ON h.legal_case_id = lc.id WHERE lc.user_id IN ($users)");
        $conn->executeStatement("DELETE lcd FROM legal_case_debtor lcd JOIN legal_case lc ON lcd.legal_case_id = lc.id WHERE lc.user_id IN ($users)");
        $conn->executeStatement("DELETE FROM legal_case WHERE user_id IN ($users)");
        $conn->executeStatement("DELETE FROM debtor WHERE user_id IN ($users)");
        $conn->executeStatement("DELETE FROM creditor WHERE user_id IN ($users)");
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->prefix . '%']);
        foreach ($this->courtIds as $id) {
            $conn->executeStatement('DELETE FROM court WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }
}
