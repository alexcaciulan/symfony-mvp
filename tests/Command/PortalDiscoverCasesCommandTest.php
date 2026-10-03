<?php

declare(strict_types=1);

namespace App\Tests\Command;

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

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'portal-discover-' . uniqid();
        static::getContainer()->set(PortalJustClient::class, $this->portalListing());
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
        return new class extends PortalJustClient {
            public function __construct()
            {
                parent::__construct(new NullLogger());
            }

            public function searchByParty(string $partyName, string $institutionCode, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
            {
                if ($partyName !== 'HEALTHU WORLDWIDE') {
                    return [];
                }

                return [[
                    'numar' => '19883/211/2025',
                    'institutie' => $institutionCode,
                    'departament' => 'Civil',
                    'categorieCaz' => null,
                    'stadiuProcesual' => 'Fond',
                    'obiect' => 'ordonanță de plată',
                    'dataModificare' => '2026-03-01',
                    'parti' => [
                        ['nume' => 'EXPERT SERVICE SUPPLY SRL', 'calitateParte' => 'Creditor'],
                        ['nume' => 'HEALTHU WORLDWIDE SRL', 'calitateParte' => 'Debitor'],
                    ],
                    'sedinte' => [],
                    'caiAtac' => [],
                ]];
            }
        };
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
        $this->em->flush();
        $this->courtIds[] = (int) $court->getId();

        return $case;
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $users = 'SELECT id FROM user WHERE email LIKE ' . $conn->quote($this->prefix . '%');
        $conn->executeStatement("DELETE FROM notification WHERE user_id IN ($users)");
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
