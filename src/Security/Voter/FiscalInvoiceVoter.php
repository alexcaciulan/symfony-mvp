<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\FiscalInvoice;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorizes viewing/downloading a fiscal invoice: the owner or an admin.
 */
class FiscalInvoiceVoter extends Voter
{
    public const VIEW = 'FISCAL_INVOICE_VIEW';
    public const DOWNLOAD = 'FISCAL_INVOICE_DOWNLOAD';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::DOWNLOAD], true)
            && $subject instanceof FiscalInvoice;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var FiscalInvoice $subject */
        return match ($attribute) {
            self::VIEW, self::DOWNLOAD => in_array('ROLE_ADMIN', $user->getRoles(), true)
                || $subject->getUser() === $user,
            default => false,
        };
    }
}
