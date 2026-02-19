<?php

namespace App\Tests\Security\Voter;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Security\Voter\CaseVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class CaseVoterTest extends TestCase
{
    private CaseVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new CaseVoter();
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    public function testOwnerCanView(): void
    {
        $user = new User();
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus('pending_payment');

        $token = $this->createToken($user);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $case, [CaseVoter::VIEW])
        );
    }

    public function testOtherUserCannotView(): void
    {
        $owner = new User();
        $other = new User();
        $case = new LegalCase();
        $case->setUser($owner);
        $case->setStatus('pending_payment');

        $token = $this->createToken($other);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $case, [CaseVoter::VIEW])
        );
    }

    public function testAdminCanView(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $owner = new User();
        $case = new LegalCase();
        $case->setUser($owner);
        $case->setStatus('pending_payment');

        $token = $this->createToken($admin);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $case, [CaseVoter::VIEW])
        );
    }

    public function testOwnerCanEditDraft(): void
    {
        $user = new User();
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus('draft');

        $token = $this->createToken($user);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $case, [CaseVoter::EDIT])
        );
    }

    public function testOwnerCannotEditNonDraft(): void
    {
        $user = new User();
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus('pending_payment');

        $token = $this->createToken($user);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $case, [CaseVoter::EDIT])
        );
    }

    public function testAdminCanEditNonDraft(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $owner = new User();
        $case = new LegalCase();
        $case->setUser($owner);
        $case->setStatus('pending_payment');

        $token = $this->createToken($admin);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $case, [CaseVoter::EDIT])
        );
    }
}
