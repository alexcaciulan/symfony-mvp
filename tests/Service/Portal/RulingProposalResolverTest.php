<?php

declare(strict_types=1);

namespace App\Tests\Service\Portal;

use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\PortalEventType;
use App\Service\Portal\RulingProposalResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RulingProposalResolverTest extends KernelTestCase
{
    private RulingProposalResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = static::getContainer()->get(RulingProposalResolver::class);
    }

    private function event(PortalEventType $type, ?string $solutie, ?string $eventDate = '2026-03-10'): CourtPortalEvent
    {
        $event = new CourtPortalEvent();
        $event->setEventType($type);
        $event->setDescription('Ședință finalizată');
        $event->setSolutie($solutie);
        if ($eventDate !== null) {
            $event->setEventDate(new \DateTime($eventDate));
        }

        return $event;
    }

    private function caseWith(CaseStatus $status): LegalCase
    {
        $case = new LegalCase();
        $case->setStatus($status);

        return $case;
    }

    public function testSuggestsEmiteOrdonantaOnAdmiteFromTermenFixat(): void
    {
        $case = $this->caseWith(CaseStatus::TERMEN_FIXAT);
        $event = $this->event(PortalEventType::HEARING_COMPLETED, 'Admite cererea');

        self::assertSame('emite_ordonanta', $this->resolver->suggestedTransition($case, $event));
    }

    public function testSuggestsRespingeOnRespingeFromTermenFixat(): void
    {
        $case = $this->caseWith(CaseStatus::TERMEN_FIXAT);
        $event = $this->event(PortalEventType::HEARING_COMPLETED, 'Respinge cererea');

        self::assertSame('respinge', $this->resolver->suggestedTransition($case, $event));
    }

    public function testSuggestsAdmiteAnulareOnAnuleazaFromInAnulare(): void
    {
        $case = $this->caseWith(CaseStatus::IN_ANULARE);
        $event = $this->event(PortalEventType::HEARING_COMPLETED, 'Anulează ordonanța de plată');

        self::assertSame('admite_cerere_anulare', $this->resolver->suggestedTransition($case, $event));
    }

    public function testSuggestsRespingeAnulareOnRespingeFromInAnulare(): void
    {
        $case = $this->caseWith(CaseStatus::IN_ANULARE);
        $event = $this->event(PortalEventType::HEARING_COMPLETED, 'Respinge cererea în anulare');

        self::assertSame('respinge_cerere_anulare', $this->resolver->suggestedTransition($case, $event));
    }

    public function testActionableProposalMapsEmiteOrdonantaToIssueRulingModal(): void
    {
        $case = $this->caseWith(CaseStatus::TERMEN_FIXAT);
        $event = $this->event(PortalEventType::HEARING_COMPLETED, 'Admite cererea', '2026-03-10');

        $proposal = $this->resolver->actionableProposal($case, [$event]);

        self::assertNotNull($proposal);
        self::assertSame('emite_ordonanta', $proposal->transition);
        self::assertSame('#hs-modal-issue-ruling', $proposal->modalTarget);
        self::assertSame('2026-03-10', $proposal->prefillDate->format('Y-m-d'));
    }

    public function testActionableProposalMapsAdmiteAnulareToRejectModal(): void
    {
        $case = $this->caseWith(CaseStatus::IN_ANULARE);
        $event = $this->event(PortalEventType::HEARING_COMPLETED, 'Anulează ordonanța de plată');

        $proposal = $this->resolver->actionableProposal($case, [$event]);

        self::assertNotNull($proposal);
        self::assertSame('admite_cerere_anulare', $proposal->transition);
        self::assertSame('#hs-modal-reject', $proposal->modalTarget);
    }

    public function testActionableProposalNullWhenTransitionNotApplicableFromStatus(): void
    {
        // From AMIABIL, emite_ordonanta is not enabled → no proposal.
        $case = $this->caseWith(CaseStatus::AMIABIL);
        $event = $this->event(PortalEventType::HEARING_COMPLETED, 'Admite cererea');

        self::assertNull($this->resolver->actionableProposal($case, [$event]));
    }

    public function testSuggestsAdmiteAnulareOnAnuleazaFromExecutare(): void
    {
        // Enforcement started while the annulment was pending; the court grants it.
        $case = $this->caseWith(CaseStatus::EXECUTARE);
        $event = $this->event(PortalEventType::HEARING_COMPLETED, 'Anulează ordonanța de plată');

        self::assertSame('admite_cerere_anulare', $this->resolver->suggestedTransition($case, $event));
    }

    public function testSuggestsRespingeAnulareExecutareOnRespingeFromExecutare(): void
    {
        $case = $this->caseWith(CaseStatus::EXECUTARE);
        $event = $this->event(PortalEventType::HEARING_COMPLETED, 'Respinge cererea în anulare');

        self::assertSame('respinge_cerere_anulare_executare', $this->resolver->suggestedTransition($case, $event));
    }

    public function testActionableProposalMapsRespingeAnulareToAnnulmentRejectedModal(): void
    {
        // respinge_cerere_anulare is now surfaced with its own confirmation modal.
        $case = $this->caseWith(CaseStatus::IN_ANULARE);
        $event = $this->event(PortalEventType::HEARING_COMPLETED, 'Respinge cererea în anulare');

        $proposal = $this->resolver->actionableProposal($case, [$event]);

        self::assertNotNull($proposal);
        self::assertSame('respinge_cerere_anulare', $proposal->transition);
        self::assertSame('#hs-modal-annulment-rejected', $proposal->modalTarget);
    }

    public function testActionableProposalMapsRespingeAnulareExecutareToAnnulmentRejectedModal(): void
    {
        $case = $this->caseWith(CaseStatus::EXECUTARE);
        $event = $this->event(PortalEventType::HEARING_COMPLETED, 'Respinge cererea în anulare');

        $proposal = $this->resolver->actionableProposal($case, [$event]);

        self::assertNotNull($proposal);
        self::assertSame('respinge_cerere_anulare_executare', $proposal->transition);
        self::assertSame('#hs-modal-annulment-rejected', $proposal->modalTarget);
    }

    public function testActionableProposalSkipsNonRulingEvents(): void
    {
        $case = $this->caseWith(CaseStatus::TERMEN_FIXAT);
        $event = $this->event(PortalEventType::HEARING_SCHEDULED, null);

        self::assertNull($this->resolver->actionableProposal($case, [$event]));
    }
}
