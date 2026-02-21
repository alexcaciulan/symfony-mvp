<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserEntityTest extends TestCase
{
    public function testGetRolesAlwaysIncludesRoleUser(): void
    {
        $user = new User();
        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    public function testGetRolesReturnsUniqueValues(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        $roles = $user->getRoles();
        $this->assertSame(count($roles), count(array_unique($roles)));
        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
    }

    public function testGetRolesWithEmptyRoles(): void
    {
        $user = new User();
        $user->setRoles([]);

        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testGetFullNameWithBothNames(): void
    {
        $user = new User();
        $user->setFirstName('Ion');
        $user->setLastName('Popescu');

        $this->assertSame('Ion Popescu', $user->getFullName());
    }

    public function testGetFullNameWithOnlyFirstName(): void
    {
        $user = new User();
        $user->setFirstName('Ion');

        $this->assertSame('Ion', $user->getFullName());
    }

    public function testGetFullNameWithOnlyLastName(): void
    {
        $user = new User();
        $user->setLastName('Popescu');

        $this->assertSame('Popescu', $user->getFullName());
    }

    public function testGetFullNameReturnsNullWhenNoNames(): void
    {
        $user = new User();

        $this->assertNull($user->getFullName());
    }

    public function testIsDeletedReturnsTrueWhenDeletedAtSet(): void
    {
        $user = new User();
        $user->setDeletedAt(new \DateTimeImmutable());

        $this->assertTrue($user->isDeleted());
    }

    public function testIsDeletedReturnsFalseWhenDeletedAtNull(): void
    {
        $user = new User();

        $this->assertFalse($user->isDeleted());
    }

    public function testGetUserIdentifierReturnsEmail(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');

        $this->assertSame('test@example.com', $user->getUserIdentifier());
    }

    public function testToStringReturnsFullNameOrEmail(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $this->assertSame('test@example.com', (string) $user);

        $user->setFirstName('Ion');
        $this->assertSame('Ion', (string) $user);

        $user->setLastName('Popescu');
        $this->assertSame('Ion Popescu', (string) $user);
    }
}
