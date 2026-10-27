<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\FilingEmailPart;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\FilingDeliveryStatus;
use App\Service\Filing\FilingRowLocker;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FilingRowLockerTest extends KernelTestCase
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
        $this->filingUploadsDir = static::getContainer()->getParameter('app.uploads_dir');
        $this->user = $this->createFilingUser($this->em, 'filing-row-locker');
        $this->courtTransport()->reset();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        $this->removeFilingFixtures($this->em, $this->user, $this->case ?? null);
        parent::tearDown();
    }

    public function testLocksTheCaseThenTheSubmissionThenEachPartAndRefreshesEveryRow(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $submission = $this->submitFiling($this->case, $this->user);
        $parts = [...$submission->getParts()];
        self::assertCount(2, $parts);

        // Another transaction changes every row after this one loaded them.
        $connection = $this->em->getConnection();
        $connection->executeStatement('UPDATE legal_case SET status = ? WHERE id = ?', [CaseStatus::CERERE_DEPUSA->value, $this->case->getId()]);
        $connection->executeStatement('UPDATE filing_submission SET delivery_status = ? WHERE id = ?', [FilingDeliveryStatus::SENT->value, $submission->getId()]);
        $connection->executeStatement('UPDATE filing_email_part SET attempt_count = 7 WHERE submission_id = ?', [$submission->getId()]);

        $recorder = new class($this->em) extends EntityManagerDecorator {
            /** @var list<array{string, object}> */
            public array $calls = [];

            public function lock(object $entity, LockMode|int $lockMode, \DateTimeInterface|int|null $lockVersion = null): void
            {
                $this->calls[] = ['lock', $entity];
                parent::lock($entity, $lockMode, $lockVersion);
            }

            public function refresh(object $object, LockMode|int|null $lockMode = null): void
            {
                $this->calls[] = ['refresh', $object];
                parent::refresh($object, $lockMode);
            }
        };

        $connection->beginTransaction();
        (new FilingRowLocker($recorder))->lock($submission);
        $connection->commit();

        $expected = [
            ['lock', $this->case], ['refresh', $this->case],
            ['lock', $submission], ['refresh', $submission],
        ];
        foreach ($parts as $part) {
            $expected[] = ['lock', $part];
            $expected[] = ['refresh', $part];
        }
        self::assertSame(
            array_map(self::describe(...), $expected),
            array_map(self::describe(...), $recorder->calls),
        );

        self::assertSame(CaseStatus::CERERE_DEPUSA, $this->case->getStatus());
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus());
        foreach ($parts as $part) {
            self::assertSame(7, $part->getAttemptCount());
        }
    }

    /** @param array{string, object} $call */
    private static function describe(array $call): string
    {
        [$method, $entity] = $call;
        $id = match (true) {
            $entity instanceof LegalCase, $entity instanceof FilingSubmission, $entity instanceof FilingEmailPart => $entity->getId(),
            default => spl_object_id($entity),
        };

        return sprintf('%s %s#%s', $method, $entity::class, $id);
    }
}
