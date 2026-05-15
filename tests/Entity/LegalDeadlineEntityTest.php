<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use PHPUnit\Framework\TestCase;

class LegalDeadlineEntityTest extends TestCase
{
    public function testDefaults(): void
    {
        $deadline = new LegalDeadline();

        $this->assertSame(DeadlinePriority::MEDIUM, $deadline->getPriority());
        $this->assertFalse($deadline->isCompleted());
        $this->assertNull($deadline->getCompletedAt());
        $this->assertNull($deadline->getCompletedBy());
        $this->assertFalse($deadline->isAlertSent7());
        $this->assertFalse($deadline->isAlertSent3());
        $this->assertFalse($deadline->isAlertSent1());
        $this->assertFalse($deadline->isAlertSentExpired());
    }

    public function testGettersAndSetters(): void
    {
        $deadline = new LegalDeadline();
        $case = new LegalCase();
        $date = new \DateTimeImmutable('2026-06-15');

        $deadline->setLegalCase($case);
        $deadline->setType(DeadlineType::CERERE_IN_ANULARE);
        $deadline->setDeadlineDate($date);
        $deadline->setDescription('Termen cerere în anulare');
        $deadline->setPriority(DeadlinePriority::HIGH);

        $this->assertSame($case, $deadline->getLegalCase());
        $this->assertSame(DeadlineType::CERERE_IN_ANULARE, $deadline->getType());
        $this->assertSame($date, $deadline->getDeadlineDate());
        $this->assertSame('Termen cerere în anulare', $deadline->getDescription());
        $this->assertSame(DeadlinePriority::HIGH, $deadline->getPriority());
    }

    public function testMarkCompletedSetsBothFields(): void
    {
        $deadline = new LegalDeadline();

        $deadline->markCompleted();

        $this->assertTrue($deadline->isCompleted());
        $this->assertInstanceOf(\DateTimeImmutable::class, $deadline->getCompletedAt());
        $this->assertNull($deadline->getCompletedBy());
    }

    public function testMarkCompletedRecordsUser(): void
    {
        $deadline = new LegalDeadline();
        $user = new User();
        $user->setEmail('marker@test.com');

        $deadline->markCompleted($user);

        $this->assertTrue($deadline->isCompleted());
        $this->assertSame($user, $deadline->getCompletedBy());
    }

    public function testMarkCompletedIsIdempotentOnEntity(): void
    {
        $deadline = new LegalDeadline();
        $firstUser = new User();
        $firstUser->setEmail('first@test.com');
        $secondUser = new User();
        $secondUser->setEmail('second@test.com');

        $deadline->markCompleted($firstUser);
        $firstCompletedAt = $deadline->getCompletedAt();

        // Apel repetat — completedAt și completedBy rămân înghețate.
        $deadline->markCompleted($secondUser);

        $this->assertSame($firstCompletedAt, $deadline->getCompletedAt());
        $this->assertSame($firstUser, $deadline->getCompletedBy());
    }

    public function testAlertFlagsCanBeToggled(): void
    {
        $deadline = new LegalDeadline();

        $deadline->setAlertSent7(true);
        $deadline->setAlertSent3(true);
        $deadline->setAlertSent1(true);
        $deadline->setAlertSentExpired(true);

        $this->assertTrue($deadline->isAlertSent7());
        $this->assertTrue($deadline->isAlertSent3());
        $this->assertTrue($deadline->isAlertSent1());
        $this->assertTrue($deadline->isAlertSentExpired());
    }
}
