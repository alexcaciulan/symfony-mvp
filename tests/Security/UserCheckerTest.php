<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class UserCheckerTest extends TestCase
{
    public function testSoftDeletedUserIsBlocked(): void
    {
        $user = new User();
        $user->setDeletedAt(new \DateTimeImmutable());

        $this->expectException(CustomUserMessageAccountStatusException::class);
        (new UserChecker())->checkPostAuth($user);
    }

    public function testActiveUserPasses(): void
    {
        $user = new User();
        $checker = new UserChecker();

        $checker->checkPreAuth($user);
        $checker->checkPostAuth($user);

        // No exception thrown for an active account (verified or not is irrelevant here).
        $this->addToAssertionCount(1);
    }
}
