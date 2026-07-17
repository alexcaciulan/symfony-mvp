<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The email UniqueEntity constraint is scoped to the `admin` group so public registration
 * (Default group) never surfaces a "email already exists" error, which would re-open the
 * enumeration oracle; EasyAdmin opts into the `admin` group to keep its friendly validation.
 */
final class UserEmailUniquenessTest extends KernelTestCase
{
    public function testEmailUniquenessFiresOnlyInAdminGroup(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $validator = static::getContainer()->get(ValidatorInterface::class);

        $prefix = 'uniq-' . uniqid();
        $email = $prefix . '@test.com';

        $existing = new User();
        $existing->setEmail($email);
        $existing->setPassword('x');
        $em->persist($existing);
        $em->flush();

        try {
            $candidate = new User();
            $candidate->setEmail($email);
            $candidate->setPassword('x');

            // Default group (registration): uniqueness must NOT fire.
            foreach ($validator->validate($candidate) as $violation) {
                self::assertNotSame('email', $violation->getPropertyPath(), 'Uniqueness must not fire in the Default group.');
            }

            // Admin group (EasyAdmin): uniqueness must fire.
            $hasEmailViolation = false;
            foreach ($validator->validate($candidate, null, ['admin']) as $violation) {
                if ($violation->getPropertyPath() === 'email') {
                    $hasEmailViolation = true;
                }
            }
            self::assertTrue($hasEmailViolation, 'Uniqueness must fire in the admin group.');
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM user WHERE email LIKE ?', [$prefix . '%']);
        }
    }
}
