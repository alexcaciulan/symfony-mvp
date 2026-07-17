<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the securityStamp / EquatableInterface session-revocation lever (audit #8).
 */
final class UserSecurityStampTest extends TestCase
{
    public function testConstructorSeedsAStamp(): void
    {
        self::assertNotEmpty((new User())->getSecurityStamp());
    }

    public function testRegenerateChangesTheStamp(): void
    {
        $user = new User();
        $before = $user->getSecurityStamp();
        $user->regenerateSecurityStamp();

        self::assertNotSame($before, $user->getSecurityStamp());
    }

    public function testIsEqualToTrueForSameStampIdentifierAndUnchangedPassword(): void
    {
        [$session, $db] = $this->pair('user@test.com', 'stamp-A', 'bcrypt-hash');

        self::assertTrue($session->isEqualTo($db));
    }

    public function testIsEqualToFalseWhenStampDiffers(): void
    {
        [$session, $db] = $this->pair('user@test.com', 'stamp-A', 'bcrypt-hash');
        $this->setStamp($db, 'stamp-B');

        self::assertFalse($session->isEqualTo($db), 'A bumped stamp must invalidate the session.');
    }

    public function testIsEqualToFalseWhenPasswordChanged(): void
    {
        [$session, $db] = $this->pair('user@test.com', 'stamp-A', 'bcrypt-hash');
        $db->setPassword('a-different-bcrypt-hash');

        self::assertFalse($session->isEqualTo($db), 'A changed password must invalidate the session even with the same stamp.');
    }

    public function testIsEqualToFalseWhenRolesChanged(): void
    {
        [$session, $db] = $this->pair('user@test.com', 'stamp-A', 'bcrypt-hash');
        $session->setRoles(['ROLE_ADMIN']);
        // The DB user keeps the default role set: a revoked ROLE_ADMIN must invalidate the live session.
        self::assertFalse($session->isEqualTo($db), 'A revoked role must invalidate the session even with the same stamp and password.');
    }

    public function testIsEqualToFalseForDifferentIdentifier(): void
    {
        [$session] = $this->pair('user@test.com', 'stamp-A', 'bcrypt-hash');
        [, $other] = $this->pair('other@test.com', 'stamp-A', 'bcrypt-hash');

        self::assertFalse($session->isEqualTo($other));
    }

    /**
     * Builds a (session-side, db-side) User pair for the same account. The session user carries
     * the crc32c password digest that __serialize() would have produced; the db user the full hash.
     *
     * @return array{0: User, 1: User}
     */
    private function pair(string $email, string $stamp, string $dbPassword): array
    {
        $session = new User();
        $session->setEmail($email);
        $session->setPassword(hash('crc32c', $dbPassword));
        $this->setStamp($session, $stamp);

        $db = new User();
        $db->setEmail($email);
        $db->setPassword($dbPassword);
        $this->setStamp($db, $stamp);

        return [$session, $db];
    }

    private function setStamp(User $user, string $stamp): void
    {
        $ref = new \ReflectionProperty(User::class, 'securityStamp');
        $ref->setValue($user, $stamp);
    }
}
