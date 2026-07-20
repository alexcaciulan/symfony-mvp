<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\FiscalInvoice;
use App\Entity\User;
use App\Security\Voter\FiscalInvoiceVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class FiscalInvoiceVoterTest extends TestCase
{
    private function token(?User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function invoiceFor(User $owner): FiscalInvoice
    {
        return (new FiscalInvoice())->setUser($owner);
    }

    public function testOwnerCanViewAndDownload(): void
    {
        $owner = new User();
        $voter = new FiscalInvoiceVoter();

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($owner), $this->invoiceFor($owner), [FiscalInvoiceVoter::VIEW]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($owner), $this->invoiceFor($owner), [FiscalInvoiceVoter::DOWNLOAD]));
    }

    public function testNonOwnerDenied(): void
    {
        $owner = new User();
        $intruder = new User();
        $voter = new FiscalInvoiceVoter();

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($intruder), $this->invoiceFor($owner), [FiscalInvoiceVoter::VIEW]));
    }

    public function testAdminCanView(): void
    {
        $owner = new User();
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $voter = new FiscalInvoiceVoter();

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($admin), $this->invoiceFor($owner), [FiscalInvoiceVoter::VIEW]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($admin), $this->invoiceFor($owner), [FiscalInvoiceVoter::DOWNLOAD]));
    }
}
