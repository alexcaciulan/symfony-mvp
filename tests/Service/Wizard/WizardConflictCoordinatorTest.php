<?php

declare(strict_types=1);

namespace App\Tests\Service\Wizard;

use App\DTO\Extraction\ConflictOption;
use App\DTO\Extraction\ConflictResolution;
use App\DTO\Extraction\PrefillConflict;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Service\Extraction\ConflictResolutionService;
use App\Service\Wizard\WizardBag;
use App\Service\Wizard\WizardConflictCoordinator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The settlement of a step is decided from the posted request and the bag
 * alone, so no document is needed: the conflicts are handed in as the
 * aggregation would have built them.
 */
final class WizardConflictCoordinatorTest extends KernelTestCase
{
    private WizardConflictCoordinator $coordinator;
    private ConflictResolutionService $resolutions;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->coordinator = static::getContainer()->get(WizardConflictCoordinator::class);
        $this->resolutions = static::getContainer()->get(ConflictResolutionService::class);
    }

    public function testAStepWithNothingToSettleAdvances(): void
    {
        $settlement = $this->coordinator->settleConflicts($this->post([]), new WizardBag(), 'creditor', []);

        self::assertTrue($settlement->settled);
        self::assertNull($settlement->flashKey);
    }

    public function testABlockingChoiceLeftUnansweredRefusesTheStep(): void
    {
        $conflict = $this->choice();
        $bag = new WizardBag();

        $settlement = $this->coordinator->settleConflicts($this->post([]), $bag, 'creditor', [$conflict]);

        self::assertFalse($settlement->settled);
        self::assertSame('wizard.conflict.choice_required', $settlement->flashKey);
        self::assertSame([], $bag->conflictResolutions);
    }

    public function testAnAnsweredChoiceIsKeptInTheBagAndTheStepAdvances(): void
    {
        $conflict = $this->choice();
        $bag = new WizardBag();

        $settlement = $this->coordinator->settleConflicts(
            $this->post(['prefill_conflict' => [$conflict->key() => '1']]),
            $bag,
            'creditor',
            [$conflict],
        );

        self::assertTrue($settlement->settled);
        self::assertArrayHasKey($conflict->key(), $bag->conflictResolutions);
        self::assertSame(1, $bag->conflictResolutions[$conflict->key()]->optionIndex);
    }

    public function testADisagreementOnlyTheDocumentsCanSettleRefusesTheStep(): void
    {
        $conflict = new PrefillConflict(
            scope: ConflictScope::CLAIM,
            severity: ConflictSeverity::ERROR,
            messageKey: 'wizard.conflict.claim.two_currencies',
            field: 'currency',
            acknowledgeable: false,
        );

        $settlement = $this->coordinator->settleConflicts($this->post([]), new WizardBag(), 'claim', [$conflict]);

        self::assertFalse($settlement->settled);
        self::assertSame('wizard.conflict.documents_change_required', $settlement->flashKey);
    }

    public function testTheAcknowledgementIsBoundToTheDisagreementsItWasMadeAbout(): void
    {
        $first = $this->acknowledgeable('wizard.conflict.debtor_set.two_debtors');
        $bag = new WizardBag();

        $refused = $this->coordinator->settleConflicts($this->post([]), $bag, 'debtors', [$first]);
        self::assertFalse($refused->settled);
        self::assertSame('wizard.conflict.acknowledge_required', $refused->flashKey);
        self::assertSame([], $bag->conflictsAcknowledged);

        $stated = $this->coordinator->settleConflicts(
            $this->post(['prefill_conflicts_acknowledged' => '1']),
            $bag,
            'debtors',
            [$first],
        );
        self::assertTrue($stated->settled);
        self::assertSame(
            $this->resolutions->acknowledgementFingerprint([$first]),
            $bag->conflictsAcknowledged['debtors']['fingerprint'] ?? null,
        );
        self::assertNotEmpty($bag->conflictsAcknowledged['debtors']['at'] ?? null);

        // Walking back to the step does not ask again for the same set.
        $again = $this->coordinator->settleConflicts($this->post([]), $bag, 'debtors', [$first]);
        self::assertTrue($again->settled);

        // A document uploaded afterwards raises another one: the statement
        // made about the first set does not cover it.
        $second = $this->acknowledgeable('wizard.conflict.debtor_set.another');
        $widened = $this->coordinator->settleConflicts($this->post([]), $bag, 'debtors', [$first, $second]);
        self::assertFalse($widened->settled);
        self::assertSame('wizard.conflict.acknowledge_required', $widened->flashKey);
    }

    public function testTheRecordOfAcknowledgementsAndWhatStaysUnsettledFollowTheFingerprint(): void
    {
        $first = $this->acknowledgeable('wizard.conflict.debtor_set.two_debtors');
        $second = $this->acknowledgeable('wizard.conflict.debtor_set.another');
        $bag = new WizardBag();
        $bag->conflictsAcknowledged['debtors'] = [
            'fingerprint' => $this->resolutions->acknowledgementFingerprint([$first]),
            'at' => '2025-01-01T10:00:00+02:00',
        ];

        self::assertSame(
            [$first->key() => ['at' => '2025-01-01T10:00:00+02:00', 'fingerprint' => $bag->conflictsAcknowledged['debtors']['fingerprint']]],
            $this->coordinator->acknowledgements($bag, [$first]),
        );
        self::assertSame([], $this->coordinator->unsettledConflicts($bag, [$first]));

        self::assertSame([], $this->coordinator->acknowledgements($bag, [$first, $second]));
        self::assertSame([$first, $second], $this->coordinator->unsettledConflicts($bag, [$first, $second]));
    }

    public function testTheViewSaysWhetherTheShownSetWasAcknowledged(): void
    {
        $conflict = $this->acknowledgeable('wizard.conflict.debtor_set.two_debtors');
        $bag = new WizardBag();

        $before = $this->coordinator->conflictViewVars($bag, [$conflict], 'debtors', ['x']);
        self::assertFalse($before['prefill_conflicts_acknowledged']);
        self::assertSame(['x'], $before['prefill_conflict_rejected']);
        self::assertSame([$conflict], $before['prefill_conflicts']);

        $bag->conflictsAcknowledged['debtors'] = [
            'fingerprint' => $this->resolutions->acknowledgementFingerprint([$conflict]),
            'at' => '2025-01-01T10:00:00+02:00',
        ];
        self::assertTrue($this->coordinator->conflictViewVars($bag, [$conflict], 'debtors')['prefill_conflicts_acknowledged']);
        self::assertFalse(
            $this->coordinator->conflictViewVars($bag, [$conflict], 'creditor')['prefill_conflicts_acknowledged'],
            'A statement made on the debtor step does not count for another step.',
        );
    }

    public function testDiscardingTheChosenPartyDropsTheCardAndItsFieldChoicesOnly(): void
    {
        $bag = new WizardBag();
        $bag->debtors = new Step2DebtorsData([new Step2DebtorEntry(name: 'Alfa SRL')]);
        $bag->conflictResolutions = [
            'debtor-field' => new ConflictResolution('debtor-field', ConflictScope::DEBTOR, 'name', 'debtor-0', 'Alfa SRL', 0),
            'party' => new ConflictResolution('party', ConflictScope::DEBTOR_SET, 'debtors', null, 'Beta SRL', 1),
            'creditor-field' => new ConflictResolution('creditor-field', ConflictScope::CREDITOR, 'name', null, 'Creditor SRL', 0),
        ];

        $this->coordinator->discardChosenParty($bag);

        self::assertNull($bag->debtors);
        self::assertSame(['party', 'creditor-field'], array_keys($bag->conflictResolutions));
    }

    public function testTheSnapshotIsDetachedFromTheDtoTheFormWritesInto(): void
    {
        $entry = new Step2DebtorEntry(name: 'Alfa SRL');
        $dto = new Step2DebtorsData([$entry]);

        $snapshot = $this->coordinator->snapshotDto($dto);
        $entry->name = 'Beta SRL';

        self::assertInstanceOf(Step2DebtorsData::class, $snapshot);
        self::assertSame('Alfa SRL', $snapshot->debtors[0]->name);
        self::assertNull($this->coordinator->snapshotDto(null));
    }

    /** @param array<string, mixed> $posted */
    private function post(array $posted): Request
    {
        return new Request(request: $posted);
    }

    private function choice(): PrefillConflict
    {
        return new PrefillConflict(
            scope: ConflictScope::CREDITOR,
            severity: ConflictSeverity::ERROR,
            messageKey: 'wizard.conflict.creditor.name',
            field: 'name',
            options: [new ConflictOption('Alfa SRL'), new ConflictOption('Alfa Trading SRL')],
        );
    }

    private function acknowledgeable(string $messageKey): PrefillConflict
    {
        return new PrefillConflict(
            scope: ConflictScope::DEBTOR_SET,
            severity: ConflictSeverity::ERROR,
            messageKey: $messageKey,
            field: 'debtors',
        );
    }
}
