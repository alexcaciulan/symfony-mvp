<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class CaseVoter extends Voter
{
    public const VIEW = 'CASE_VIEW';
    public const EDIT = 'CASE_EDIT';
    public const UPLOAD = 'CASE_UPLOAD';
    public const TRANSITION = 'CASE_TRANSITION';

    /**
     * Permite managementul termenelor procedurale (mark complete, add custom,
     * set rulingCommunicationDate) — ownership-only, FĂRĂ restricție de status.
     * Termenele pot exista pe dosare în orice stadiu (inclusiv ORDONANTA_EMISA,
     * DEFINITIVA, INCHIS_SUCCES) și avocatul trebuie să poată marca completarea
     * oricând. CASE_EDIT e prea restrictiv aici (limitat la AMIABIL/SOMATIE/CERERE).
     */
    public const DEADLINE_MANAGE = 'CASE_DEADLINE_MANAGE';

    private const EDITABLE_STATUSES = [
        CaseStatus::AMIABIL,
        CaseStatus::SOMATIE_TRIMISA,
        CaseStatus::CERERE_DEPUSA,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::UPLOAD, self::TRANSITION, self::DEADLINE_MANAGE], true)
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

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($legalCase, $user),
            self::EDIT => $this->canEdit($legalCase, $user),
            self::UPLOAD => $this->canUpload($legalCase, $user),
            self::TRANSITION => $this->canTransition($legalCase, $user),
            self::DEADLINE_MANAGE => $this->canManageDeadlines($legalCase, $user),
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
            && in_array($legalCase->getStatus(), self::EDITABLE_STATUSES, true);
    }

    private function canUpload(LegalCase $legalCase, User $user): bool
    {
        return $legalCase->getUser() === $user
            && in_array($legalCase->getStatus(), self::EDITABLE_STATUSES, true);
    }

    private function canTransition(LegalCase $legalCase, User $user): bool
    {
        return $legalCase->getUser() === $user;
    }

    private function canManageDeadlines(LegalCase $legalCase, User $user): bool
    {
        return $legalCase->getUser() === $user;
    }
}
