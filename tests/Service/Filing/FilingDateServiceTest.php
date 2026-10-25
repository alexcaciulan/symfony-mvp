<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\AuditLog;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Service\Filing\FilingDateRefusedException;
use App\Service\Filing\FilingDateService;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FilingDateServiceTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;

    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'filing-date');
        $this->courtTransport()->reset();
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testNotOfferedBeforeThePlatformTransmittedThePetition(): void
    {
        self::assertFalse($this->service()->canSet($this->case));

        $this->expectExceptionObject(new FilingDateRefusedException('filing.status.set_date.error_not_allowed'));
        $this->service()->setFromLawyer($this->case, new \DateTimeImmutable('today'), $this->user);
    }

    public function testFutureDateIsRefused(): void
    {
        $this->transmit();

        $this->assertRefused(new \DateTimeImmutable('tomorrow'), 'filing.status.set_date.error_future');
    }

    public function testDateBeforeTheTransmissionIsRefused(): void
    {
        $this->transmit();

        $this->assertRefused(new \DateTimeImmutable('-2 days'), 'filing.status.set_date.error_before_sent');
    }

    public function testDeclaredDateIsSavedAndAudited(): void
    {
        $this->transmit();

        $this->service()->setFromLawyer($this->case, new \DateTimeImmutable('today'), $this->user);

        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $this->case->getFiledAt()?->format('Y-m-d'));
        $audit = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'filing_date_declared', 'entityId' => (string) $this->case->getId()]);
        self::assertNotNull($audit);
        self::assertSame(['filedAt' => null], $audit->getOldData());
        self::assertSame('lawyer', $audit->getNewData()['source']);
        self::assertSame($this->user->getId(), $audit->getUser()?->getId());
    }

    private function transmit(): void
    {
        $this->submitFiling($this->case, $this->user);
        $this->handleAllFilingMessages();
        $this->em->refresh($this->case);
    }

    private function assertRefused(\DateTimeImmutable $date, string $key): void
    {
        try {
            $this->service()->setFromLawyer($this->case, $date, $this->user);
            self::fail('The date must be refused.');
        } catch (FilingDateRefusedException $e) {
            self::assertSame($key, $e->messageKey);
        }
        self::assertNull($this->case->getFiledAt());
    }

    private function service(): FilingDateService
    {
        return static::getContainer()->get(FilingDateService::class);
    }
}
