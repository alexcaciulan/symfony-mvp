<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Service\Case\CaseWorkflowService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\WorkflowInterface;

class CaseWorkflowServiceTest extends KernelTestCase
{
    private CaseWorkflowService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var WorkflowInterface $workflow */
        $workflow = static::getContainer()->get('state_machine.legal_case');
        $this->service = new CaseWorkflowService($workflow);
    }

    #[DataProvider('validTransitionsProvider')]
    public function testValidTransition(CaseStatus $from, string $transition, CaseStatus $to): void
    {
        $case = new LegalCase();
        $case->setStatus($from);

        $this->assertTrue(
            $this->service->can($case, $transition),
            sprintf('Expected to be able to apply "%s" from %s', $transition, $from->value)
        );

        $this->service->apply($case, $transition);
        $this->assertSame($to, $case->getStatus());
    }

    public static function validTransitionsProvider(): array
    {
        return [
            'trimite_somatie'      => [CaseStatus::AMIABIL,            'trimite_somatie',      CaseStatus::SOMATIE_TRIMISA],
            'genereaza_cerere'     => [CaseStatus::SOMATIE_TRIMISA,    'genereaza_cerere',     CaseStatus::CERERE_GENERATA],
            'depune_cerere'        => [CaseStatus::CERERE_GENERATA,    'depune_cerere',        CaseStatus::CERERE_DEPUSA],
            'inregistreaza_dosar'  => [CaseStatus::CERERE_DEPUSA,      'inregistreaza_dosar',  CaseStatus::DOSAR_INREGISTRAT],
            'inregistreaza_dosar_din_generata' => [CaseStatus::CERERE_GENERATA, 'inregistreaza_dosar', CaseStatus::DOSAR_INREGISTRAT],
            'fixeaza_termen'       => [CaseStatus::DOSAR_INREGISTRAT,  'fixeaza_termen',       CaseStatus::TERMEN_FIXAT],
            'emite_ordonanta'      => [CaseStatus::TERMEN_FIXAT,       'emite_ordonanta',      CaseStatus::ORDONANTA_EMISA],
            'respinge'             => [CaseStatus::TERMEN_FIXAT,       'respinge',             CaseStatus::RESPINSA],
            'formuleaza_cerere_anulare' => [CaseStatus::ORDONANTA_EMISA, 'formuleaza_cerere_anulare', CaseStatus::IN_ANULARE],
            'marcheaza_definitiva'      => [CaseStatus::ORDONANTA_EMISA, 'marcheaza_definitiva',      CaseStatus::DEFINITIVA],
            'respinge_cerere_anulare'   => [CaseStatus::IN_ANULARE,      'respinge_cerere_anulare',   CaseStatus::DEFINITIVA],
            'admite_cerere_anulare'     => [CaseStatus::IN_ANULARE,      'admite_cerere_anulare',     CaseStatus::RESPINSA],
            'admite_cerere_anulare_din_executare' => [CaseStatus::EXECUTARE, 'admite_cerere_anulare', CaseStatus::RESPINSA],
            'respinge_cerere_anulare_executare'   => [CaseStatus::EXECUTARE, 'respinge_cerere_anulare_executare', CaseStatus::EXECUTARE],
            'trece_la_executare'   => [CaseStatus::DEFINITIVA,         'trece_la_executare',   CaseStatus::EXECUTARE],
            'trece_la_executare_din_ordonanta' => [CaseStatus::ORDONANTA_EMISA, 'trece_la_executare', CaseStatus::EXECUTARE],
            'trece_la_executare_din_anulare'   => [CaseStatus::IN_ANULARE,      'trece_la_executare', CaseStatus::EXECUTARE],
            'inchide_succes'       => [CaseStatus::DEFINITIVA,         'inchide_succes',       CaseStatus::INCHIS_SUCCES],
            'inchide_fara_recuperare_definitiva' => [CaseStatus::DEFINITIVA, 'inchide_fara_recuperare', CaseStatus::INCHIS_FARA_RECUPERARE],
            'inchide_succes_executare'           => [CaseStatus::EXECUTARE,  'inchide_succes',          CaseStatus::INCHIS_SUCCES],
            'inchide_fara_recuperare_executare'  => [CaseStatus::EXECUTARE,  'inchide_fara_recuperare', CaseStatus::INCHIS_FARA_RECUPERARE],
        ];
    }

    public function testInitialMarkingIsAmiabil(): void
    {
        $case = new LegalCase();
        $this->assertSame(CaseStatus::AMIABIL, $case->getStatus());
        $this->assertSame(['trimite_somatie'], $this->service->getAvailableTransitions($case));
    }

    public function testGetAvailableTransitionsFromInAnulare(): void
    {
        $case = new LegalCase();
        $case->setStatus(CaseStatus::IN_ANULARE);

        $transitions = $this->service->getAvailableTransitions($case);
        sort($transitions);
        $this->assertSame(['admite_cerere_anulare', 'respinge_cerere_anulare', 'trece_la_executare'], $transitions);
    }

    public function testGetAvailableTransitionsFromOrdonantaEmisa(): void
    {
        $case = new LegalCase();
        $case->setStatus(CaseStatus::ORDONANTA_EMISA);

        $transitions = $this->service->getAvailableTransitions($case);
        sort($transitions);
        $this->assertSame(['formuleaza_cerere_anulare', 'marcheaza_definitiva', 'trece_la_executare'], $transitions);
    }

    public function testGetAvailableTransitionsFromDefinitiva(): void
    {
        $case = new LegalCase();
        $case->setStatus(CaseStatus::DEFINITIVA);

        $transitions = $this->service->getAvailableTransitions($case);
        sort($transitions);
        $this->assertSame(['inchide_fara_recuperare', 'inchide_succes', 'trece_la_executare'], $transitions);
    }

    public function testGetAvailableTransitionsFromExecutare(): void
    {
        $case = new LegalCase();
        $case->setStatus(CaseStatus::EXECUTARE);

        $transitions = $this->service->getAvailableTransitions($case);
        sort($transitions);
        $this->assertSame(['admite_cerere_anulare', 'inchide_fara_recuperare', 'inchide_succes', 'respinge_cerere_anulare_executare'], $transitions);
    }

    public function testCannotApplyTransitionFromWrongPlace(): void
    {
        $case = new LegalCase();
        $case->setStatus(CaseStatus::AMIABIL);

        $this->assertFalse($this->service->can($case, 'depune_cerere'));
    }

    public function testTerminalRespinsaHasNoOutgoingTransitions(): void
    {
        $case = new LegalCase();
        $case->setStatus(CaseStatus::RESPINSA);

        $this->assertSame([], $this->service->getAvailableTransitions($case));
    }

    public function testTerminalInchisSuccesHasNoOutgoingTransitions(): void
    {
        $case = new LegalCase();
        $case->setStatus(CaseStatus::INCHIS_SUCCES);

        $this->assertSame([], $this->service->getAvailableTransitions($case));
    }

    public function testApplyInvalidTransitionThrows(): void
    {
        $case = new LegalCase();
        $case->setStatus(CaseStatus::AMIABIL);

        $this->expectException(NotEnabledTransitionException::class);
        $this->service->apply($case, 'inchide_succes');
    }
}
