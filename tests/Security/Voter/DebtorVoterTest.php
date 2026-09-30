<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Debtor;
use App\Entity\User;
use App\Security\Voter\DebtorVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class DebtorVoterTest extends TestCase
{
    public function testOnlyTheOwnerMayTouchTheCompany(): void
    {
        $owner = $this->user(1);
        $debtor = (new Debtor())->setUser($owner);
        $voter = new DebtorVoter();

        foreach ([DebtorVoter::VIEW, DebtorVoter::EDIT, DebtorVoter::DELETE] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($owner), $debtor, [$attribute]));
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($this->user(2)), $debtor, [$attribute]));
        }
    }

    private function user(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
    }
}
