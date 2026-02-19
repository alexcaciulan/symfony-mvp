<?php

namespace App\Tests\Service;

use App\Entity\LegalCase;
use App\Service\Case\CaseWorkflowService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

class CaseWorkflowServiceTest extends KernelTestCase
{
    private CaseWorkflowService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $workflow = static::getContainer()->get('state_machine.legal_case');
        $this->service = new CaseWorkflowService($workflow);
    }

    public function testSubmitTransitionFromDraft(): void
    {
        $case = new LegalCase();
        $case->setStatus('draft');

        $this->assertTrue($this->service->can($case, 'submit'));
        $this->service->apply($case, 'submit');
        $this->assertSame('pending_payment', $case->getStatus());
    }

    public function testConfirmPaymentTransition(): void
    {
        $case = new LegalCase();
        $case->setStatus('pending_payment');

        $this->assertTrue($this->service->can($case, 'confirm_payment'));
        $this->service->apply($case, 'confirm_payment');
        $this->assertSame('paid', $case->getStatus());
    }

    public function testCannotConfirmPaymentFromDraft(): void
    {
        $case = new LegalCase();
        $case->setStatus('draft');

        $this->assertFalse($this->service->can($case, 'confirm_payment'));
    }

    public function testCannotSubmitFromPendingPayment(): void
    {
        $case = new LegalCase();
        $case->setStatus('pending_payment');

        $this->assertFalse($this->service->can($case, 'submit'));
    }

    public function testAvailableTransitionsFromDraft(): void
    {
        $case = new LegalCase();
        $case->setStatus('draft');

        $transitions = $this->service->getAvailableTransitions($case);
        $this->assertSame(['submit'], $transitions);
    }

    public function testAvailableTransitionsFromUnderReview(): void
    {
        $case = new LegalCase();
        $case->setStatus('under_review');

        $transitions = $this->service->getAvailableTransitions($case);
        sort($transitions);
        $this->assertSame(['accept', 'reject', 'request_info'], $transitions);
    }

    public function testInvalidTransitionThrowsException(): void
    {
        $case = new LegalCase();
        $case->setStatus('draft');

        $this->expectException(\LogicException::class);
        $this->service->apply($case, 'confirm_payment');
    }
}
