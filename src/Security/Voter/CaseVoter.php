<?php

namespace App\Security\Voter;

use App\Entity\LegalCase;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class CaseVoter extends Voter
{
    public const VIEW = 'CASE_VIEW';
    public const EDIT = 'CASE_EDIT';
    public const UPLOAD = 'CASE_UPLOAD';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::UPLOAD])
            && $subject instanceof LegalCase;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var LegalCase $legalCase */
        $legalCase = $subject;

        // Admins can do everything
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($legalCase, $user),
            self::EDIT => $this->canEdit($legalCase, $user),
            self::UPLOAD => $this->canUpload($legalCase, $user),
            default => false,
        };
    }

    private function canView(LegalCase $legalCase, User $user): bool
    {
        return $legalCase->getUser() === $user;
    }

    private function canEdit(LegalCase $legalCase, User $user): bool
    {
        return $legalCase->getUser() === $user
            && $legalCase->getStatus() === 'draft';
    }

    private function canUpload(LegalCase $legalCase, User $user): bool
    {
        return $legalCase->getUser() === $user
            && in_array($legalCase->getStatus(), ['draft', 'pending_payment'], true);
    }
}
