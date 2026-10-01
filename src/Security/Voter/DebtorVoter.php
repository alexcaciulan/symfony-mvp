<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Debtor;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A company in the debtor library is the lawyer's own: only its owner may see,
 * edit or delete it.
 */
class DebtorVoter extends Voter
{
    public const EDIT = 'DEBTOR_EDIT';
    public const DELETE = 'DEBTOR_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE], true)
            && $subject instanceof Debtor;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Debtor $subject */
        return $subject->getUser()->getId() === $user->getId();
    }
}
