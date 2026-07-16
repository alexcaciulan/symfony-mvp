<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end guard for the user_checker wiring in security.yaml: a soft-deleted account
 * cannot authenticate even with the correct password (the login fails back to /login and
 * no session is established). A typo in the firewall's user_checker id would fail this.
 */
final class DeactivatedAccountLoginTest extends WebTestCase
{
    public function testDeletedUserCannotLogInWithCorrectPassword(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $email = 'deleted-login-' . uniqid() . '@test.com';
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, 'correct-horse-123'));
        $user->setIsVerified(true);
        $user->setDeletedAt(new \DateTimeImmutable());
        $em->persist($user);
        $em->flush();

        try {
            $crawler = $client->request('GET', '/login');
            $client->submit($crawler->selectButton('Conectează-te')->form([
                '_username' => $email,
                '_password' => 'correct-horse-123',
            ]));

            // Correct password but deactivated: the login fails back to /login.
            $this->assertResponseRedirects();
            self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));

            // The deactivation message renders (proves the translated key resolves).
            $client->followRedirect();
            self::assertStringContainsString('dezactivat', (string) $client->getResponse()->getContent());
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM user WHERE email = ?', [$email]);
        }
    }
}
