<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Blocks authentication for soft-deleted (deactivated) accounts. Checked post-auth (after
 * the password) to keep login enumeration-safe: a wrong password still yields the generic
 * "invalid credentials". Currently latent (nothing sets deletedAt yet); email verification
 * is gated separately at request time by EmailVerificationSubscriber.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if ($user instanceof User && $user->isDeleted()) {
            throw new CustomUserMessageAccountStatusException('account_deactivated');
        }
    }
}
