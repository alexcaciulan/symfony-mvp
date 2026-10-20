<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\User;
use App\Enum\ClaimItemKind;
use App\Enum\ContractualAccessoryLabel;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Util\ClaimRowLiveMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The positions table lives inside the component so editing a sum or a due date
 * recomputes the per-row interest and the totals without leaving the step. We
 * drive the same server round-trip a `data-model` change triggers: set the
 * `rows` prop to an edited state and assert the rendered totals follow.
 */
final class Step3ClaimPositionsLiveTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private User $user;

    protected function setUp(): void
    {
        static::createClient();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->user = new User();
        $this->user->setEmail('live-step3-' . uniqid() . '@test.com');
        $this->user->setPassword('x');
        $this->user->setIsVerified(true);
        $em->persist($this->user);
        $em->flush();

        // The form uses session-based CSRF; the component factory builds it
        // outside an HTTP request, so push a synthetic request with an
        // in-memory session for token storage.
        $stack = static::getContainer()->get(RequestStack::class);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack->push($request);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('DELETE FROM user WHERE email LIKE ?', ['live-step3-%']);
        parent::tearDown();
    }

    public function testEditingAPositionAmountRecomputesTheTotals(): void
    {
        $rows = ClaimRowLiveMapper::toArrays([
            $this->row('TES 0036', 200.0, '2024-05-01'),
            $this->row('TES 0038', 500.0, '2024-06-15'),
        ]);

        $component = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(currency: 'RON', relationshipType: RelationshipType::COMERCIAL),
            'rows' => $rows,
        ])->actingAs($this->user);

        $before = (string) $component->render();
        self::assertStringContainsString('700.00', $before, 'Sidebar principal is the sum of the two positions');

        // Edit the second position's amount, the way a data-model change would.
        $rows[1]['amount'] = '1.234,56';
        $after = (string) $component->set('rows', $rows)->render();

        // 200 + 1234.56 = 1434.56: the total followed the edit.
        self::assertStringContainsString('1,434.56', $after);
        self::assertStringNotContainsString('700.00', $after);
    }

    public function testExcludingAPositionDropsItFromTheTotal(): void
    {
        $rows = ClaimRowLiveMapper::toArrays([
            $this->row('TES 0036', 200.0, '2024-05-01'),
            $this->row('TES 0038', 500.0, '2024-06-15'),
        ]);

        $component = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(currency: 'RON', relationshipType: RelationshipType::COMERCIAL),
            'rows' => $rows,
        ])->actingAs($this->user);

        $rows[1]['excluded'] = true;
        $after = (string) $component->set('rows', $rows)->render();

        self::assertStringContainsString('200.00', $after);
        self::assertStringNotContainsString('700.00', $after);
    }

    public function testCreditNoteRowRendersItsKindBadge(): void
    {
        // Guards the badge whose condition matched the enum name ('CREDIT_NOTE')
        // while the row carries the enum value ('credit_note'): the badge never
        // rendered. The label comes from the claim_item_kind catalogue.
        $creditNote = new ClaimItemRow(
            dedupKey: 'cn:STORNO-1',
            amount: 150.0,
            currency: 'RON',
            kind: ClaimItemKind::CREDIT_NOTE,
            documentNumber: 'STORNO-1',
            dueDate: new \DateTimeImmutable('2024-06-01'),
            amountRon: 150.0,
            confirmed: true,
        );

        $rows = ClaimRowLiveMapper::toArrays([
            $this->row('TES 0036', 200.0, '2024-05-01'),
            $creditNote,
        ]);

        $component = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(currency: 'RON', relationshipType: RelationshipType::COMERCIAL),
            'rows' => $rows,
        ])->actingAs($this->user);

        $html = (string) $component->render();

        $translator = static::getContainer()->get('translator');
        self::assertStringContainsString(
            $translator->trans('enum.claim_item_kind.credit_note', locale: 'ro'),
            $html,
            'Credit-note position must show its kind badge in the live table.',
        );
    }

    public function testContractualPositionsUseTheContractTermInsteadOfInterest(): void
    {
        $rows = ClaimRowLiveMapper::toArrays([$this->row('TES 0036', 1000.0, '2024-05-01')]);

        $component = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(
                currency: 'RON',
                relationshipType: RelationshipType::COMERCIAL,
                penaltyType: PenaltyType::CONTRACTUAL,
                contractualPenaltyRate: 0.1,
                contractualAccessoryLabel: ContractualAccessoryLabel::MAJORARI_INTARZIERE,
            ),
            'rows' => $rows,
        ])->actingAs($this->user);

        $html = (string) $component->render();
        $translator = static::getContainer()->get('translator');

        self::assertStringContainsString($translator->trans('enum.contractual_accessory_label.MAJORARI_INTARZIERE', locale: 'ro'), $html);
        self::assertStringNotContainsString($translator->trans('wizard.step3.sidebar.interest_label', locale: 'ro'), $html);
        self::assertStringNotContainsString($translator->trans('wizard.step3.claim_items.column.interest', locale: 'ro') . '</span>', $html);
    }

    public function testContractualWithoutRateShowsNoStatutoryFigureUnderThePenaltyLabel(): void
    {
        $component = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(
                currency: 'RON',
                relationshipType: RelationshipType::COMERCIAL,
                penaltyType: PenaltyType::CONTRACTUAL,
            ),
            'rows' => ClaimRowLiveMapper::toArrays([$this->row('TES 0036', 1000.0, '2024-05-01')]),
        ])->actingAs($this->user);

        $html = (string) $component->render();
        $translator = static::getContainer()->get('translator');

        self::assertStringNotContainsString('data-testid="claim-item-interest"', $html, 'The aggregator falls back to statutory interest without a rate.');
        self::assertStringContainsString($translator->trans('wizard.step3.claim_items.penalty_rate_missing', locale: 'ro'), $html);
        self::assertStringContainsString($translator->trans('wizard.step3.sidebar.penalty_placeholder', locale: 'ro'), $html);
    }

    public function testPositionNamesItsSourceFileNotItsId(): void
    {
        $sourced = new ClaimItemRow(
            dedupKey: 'inv:JSA9581',
            amount: 29003.40,
            currency: 'RON',
            documentNumber: 'JSA9581',
            documentDate: new \DateTimeImmutable('2024-06-17'),
            dueDate: new \DateTimeImmutable('2024-06-22'),
            amountRon: 29003.40,
            sourceDocumentId: 24,
            confirmed: true,
        );

        $component = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(currency: 'RON', relationshipType: RelationshipType::COMERCIAL),
            'rows' => ClaimRowLiveMapper::toArrays([$sourced]),
            'sourceDocumentNames' => [24 => 'factura_JSA9581.pdf'],
        ])->actingAs($this->user);

        $html = (string) $component->render();

        self::assertStringContainsString('factura_JSA9581.pdf', $html);
        self::assertStringNotContainsString('#24', $html);
    }

    public function testADueDateSetByTheContractSaysWhyUntilTheLawyerChangesIt(): void
    {
        $row = $this->row('MJ-9/2025', 1000.0, '2025-03-05');
        $row->dueDateNote = [
            'key' => 'wizard.step3.claim_items.note.contract_term_overrides_invoice',
            'days' => 4,
            'anchor' => 'ISSUE_DATE',
            'printedDueDate' => '2025-03-01',
            'appliedDueDate' => '2025-03-05',
        ];
        $rows = ClaimRowLiveMapper::toArrays([$row]);

        $component = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(currency: 'RON', relationshipType: RelationshipType::COMERCIAL),
            'rows' => $rows,
        ])->actingAs($this->user);

        $note = static::getContainer()->get('translator')->trans(
            'wizard.step3.claim_items.note.contract_term_overrides_invoice',
            ['%count%' => 4, '{printed}' => '01.03.2025'],
            locale: 'ro',
        );
        self::assertStringContainsString('01.03.2025', $note, 'the key is translated and names the printed date');
        self::assertStringContainsString($note, (string) $component->render());

        // Once the lawyer sets another date, the contract no longer explains it.
        $rows[0]['dueDate'] = '2025-03-20';
        self::assertStringNotContainsString('claim-item-due-date-note', (string) $component->set('rows', $rows)->render());
    }

    public function testATermFromAnotherEventNamesThatEvent(): void
    {
        $row = $this->row('MJ-9/2025', 1000.0, '2025-03-01');
        $row->dueDateNote = [
            'key' => 'wizard.step3.claim_items.note.contract_term_manual',
            'days' => 30,
            'anchor' => 'DELIVERY',
            'printedDueDate' => '2025-03-01',
            'appliedDueDate' => null,
        ];

        $html = (string) $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(currency: 'RON', relationshipType: RelationshipType::COMERCIAL),
            'rows' => ClaimRowLiveMapper::toArrays([$row]),
        ])->actingAs($this->user)->render();

        $translator = static::getContainer()->get('translator');
        $note = $translator->trans('wizard.step3.claim_items.note.contract_term_manual', [
            '%count%' => 30,
            '{anchor}' => $translator->trans('enum.payment_term_anchor.DELIVERY', locale: 'ro'),
        ], locale: 'ro');
        self::assertStringContainsString('30 de zile de la livrare', $note);
        self::assertStringContainsString($note, $html);
    }

    public function testTheContractTermNoteAgreesWithTheNumberOfDays(): void
    {
        $translator = static::getContainer()->get('translator');
        $key = 'wizard.step3.claim_items.note.contract_term_applied_business_days';

        self::assertStringContainsString(': 1 zi lucrătoare de la', $translator->trans($key, ['%count%' => 1], locale: 'ro'));
        self::assertStringContainsString(': 5 zile lucrătoare de la', $translator->trans($key, ['%count%' => 5], locale: 'ro'));
        self::assertStringContainsString(': 20 de zile lucrătoare de la', $translator->trans($key, ['%count%' => 20], locale: 'ro'));
        self::assertStringContainsString(': 1 working day from', $translator->trans($key, ['%count%' => 1], locale: 'en'));
        self::assertStringContainsString(': 5 working days from', $translator->trans($key, ['%count%' => 5], locale: 'en'));
    }

    public function testAPositionPaidInFullByTheStatementShowsWhyAndEachPayment(): void
    {
        $row = $this->row('MJ-9/2025', 1000.0, '2025-03-01');
        $row->excluded = true;
        $row->paidAmount = 1000.0;
        $row->warningKeys[] = 'wizard.step3.claim_items.warning.paid_in_full_by_statement';
        $row->statementPayments = [
            ['amount' => 400.0, 'currency' => 'RON', 'date' => '2025-04-10', 'fullyPaid' => false, 'shared' => false],
            ['amount' => 600.0, 'currency' => 'RON', 'date' => '2025-05-02', 'fullyPaid' => false, 'shared' => false],
        ];

        $html = (string) $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(currency: 'RON', relationshipType: RelationshipType::COMERCIAL),
            'rows' => ClaimRowLiveMapper::toArrays([$row]),
        ])->actingAs($this->user)->render();

        $translator = static::getContainer()->get('translator');
        self::assertStringContainsString(
            htmlspecialchars($translator->trans('wizard.step3.claim_items.warning.paid_in_full_by_statement', locale: 'ro'), ENT_QUOTES),
            $html,
        );
        self::assertStringContainsString('claim-item-statement-payments', $html);
        self::assertStringContainsString('400,00 RON, la 10.04.2025', $html);
        self::assertStringContainsString('600,00 RON, la 02.05.2025', $html);
        self::assertStringContainsString('1.000,00 RON', $html);
    }

    private function row(string $number, float $amount, string $due): ClaimItemRow
    {
        return new ClaimItemRow(
            dedupKey: 'inv:' . $number,
            amount: $amount,
            currency: 'RON',
            documentNumber: $number,
            documentDate: new \DateTimeImmutable($due . ' -30 days'),
            dueDate: new \DateTimeImmutable($due),
            amountRon: $amount,
            confirmed: true,
        );
    }
}
