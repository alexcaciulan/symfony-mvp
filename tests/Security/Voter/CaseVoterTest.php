<?php

namespace App\Tests\Security\Voter;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Security\Voter\CaseVoter;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $case->setStatus(CaseStatus::TERMEN_FIXAT);

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
        $case->setStatus(CaseStatus::AMIABIL);

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
        $case->setStatus(CaseStatus::DEFINITIVA);

        $token = $this->createToken($admin);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $case, [CaseVoter::VIEW])
        );
    }

    #[DataProvider('editableStatusProvider')]
    public function testOwnerCanEditInEditableStatuses(CaseStatus $status): void
    {
        $user = new User();
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus($status);

        $token = $this->createToken($user);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $case, [CaseVoter::EDIT])
        );
    }

    public static function editableStatusProvider(): array
    {
        return [
            'AMIABIL'         => [CaseStatus::AMIABIL],
            'SOMATIE_TRIMISA' => [CaseStatus::SOMATIE_TRIMISA],
            'CERERE_DEPUSA'   => [CaseStatus::CERERE_DEPUSA],
        ];
    }

    #[DataProvider('nonEditableStatusProvider')]
    public function testOwnerCannotEditInNonEditableStatuses(CaseStatus $status): void
    {
        $user = new User();
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus($status);

        $token = $this->createToken($user);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $case, [CaseVoter::EDIT])
        );
    }

    public static function nonEditableStatusProvider(): array
    {
        return [
            'DOSAR_INREGISTRAT' => [CaseStatus::DOSAR_INREGISTRAT],
            'TERMEN_FIXAT'      => [CaseStatus::TERMEN_FIXAT],
            'ORDONANTA_EMISA'   => [CaseStatus::ORDONANTA_EMISA],
            'DEFINITIVA'        => [CaseStatus::DEFINITIVA],
            'INCHIS_SUCCES'     => [CaseStatus::INCHIS_SUCCES],
        ];
    }

    public function testAdminCanEditAnyStatus(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $owner = new User();
        $case = new LegalCase();
        $case->setUser($owner);
        $case->setStatus(CaseStatus::ORDONANTA_EMISA);

        $token = $this->createToken($admin);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $case, [CaseVoter::EDIT])
        );
    }

    public function testOwnerCanUploadInAmiabil(): void
    {
        $user = new User();
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus(CaseStatus::AMIABIL);

        $token = $this->createToken($user);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $case, [CaseVoter::UPLOAD])
        );
    }

    public function testOwnerCannotUploadInDosarInregistrat(): void
    {
        $user = new User();
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus(CaseStatus::DOSAR_INREGISTRAT);

        $token = $this->createToken($user);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $case, [CaseVoter::UPLOAD])
        );
    }

    public function testAdminCanUploadAnyStatus(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $owner = new User();
        $case = new LegalCase();
        $case->setUser($owner);
        $case->setStatus(CaseStatus::DEFINITIVA);

        $token = $this->createToken($admin);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $case, [CaseVoter::UPLOAD])
        );
    }

    public function testOwnerCanTransition(): void
    {
        $user = new User();
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus(CaseStatus::AMIABIL);

        $token = $this->createToken($user);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $case, [CaseVoter::TRANSITION])
        );
    }

    public function testOtherUserCannotTransition(): void
    {
        $owner = new User();
        $other = new User();
        $case = new LegalCase();
        $case->setUser($owner);
        $case->setStatus(CaseStatus::AMIABIL);

        $token = $this->createToken($other);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $case, [CaseVoter::TRANSITION])
        );
    }

    public function testAdminCanTransition(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $owner = new User();
        $case = new LegalCase();
        $case->setUser($owner);
        $case->setStatus(CaseStatus::TERMEN_FIXAT);

        $token = $this->createToken($admin);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $case, [CaseVoter::TRANSITION])
        );
    }

    /**
     * Pas 4.3 — DEADLINE_MANAGE: ownership-only, indiferent de status.
     * Verifică inclusiv stadii avansate (ORDONANTA_EMISA, DEFINITIVA) unde
     * CASE_EDIT ar fi denied (status non-editable).
     */
    public function testOwnerCanManageDeadlinesInAnyStatus(): void
    {
        $owner = new User();

        foreach ([CaseStatus::AMIABIL, CaseStatus::ORDONANTA_EMISA, CaseStatus::DEFINITIVA, CaseStatus::INCHIS_SUCCES] as $status) {
            $case = new LegalCase();
            $case->setUser($owner);
            $case->setStatus($status);

            $token = $this->createToken($owner);

            $this->assertSame(
                VoterInterface::ACCESS_GRANTED,
                $this->voter->vote($token, $case, [CaseVoter::DEADLINE_MANAGE]),
                sprintf('Owner trebuie să poată gestiona termene în status %s', $status->value)
            );
        }
    }

    public function testOtherUserCannotManageDeadlines(): void
    {
        $owner = new User();
        $other = new User();
        $case = new LegalCase();
        $case->setUser($owner);
        $case->setStatus(CaseStatus::AMIABIL);

        $token = $this->createToken($other);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $case, [CaseVoter::DEADLINE_MANAGE])
        );
    }
}
