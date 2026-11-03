<?php

declare(strict_types=1);

namespace App\Tests\Service\Wizard;

use App\DTO\Extraction\ConflictResolution;
use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Enum\ConflictScope;
use App\Enum\PersonType;
use App\Service\Wizard\WizardBag;
use PHPUnit\Framework\TestCase;

final class WizardBagTest extends TestCase
{
    private const EMPTY = [
        'documentIds' => [],
        'creditor' => null,
        'debtors' => null,
        'debtorBeforePick' => null,
        'debtorLibraryRecheck' => false,
        'creditorLibraryRecheck' => false,
        'claim' => null,
        'claimItems' => null,
        'claimItemsTableConfirmed' => false,
        'conflictResolutions' => [],
        'conflictsAcknowledged' => [],
    ];

    public function testANewBagSerializesToTheEmptyArrayInTheStoredKeyOrder(): void
    {
        self::assertSame(serialize(self::EMPTY), serialize((new WizardBag())->toArray()));
    }

    public function testMissingKeysTakeTheirDefaults(): void
    {
        $bag = WizardBag::fromArray([]);

        self::assertSame([], $bag->documentIds);
        self::assertNull($bag->creditor);
        self::assertNull($bag->debtors);
        self::assertNull($bag->debtorBeforePick);
        self::assertFalse($bag->debtorLibraryRecheck);
        self::assertFalse($bag->creditorLibraryRecheck);
        self::assertNull($bag->claim);
        self::assertNull($bag->claimItems);
        self::assertFalse($bag->claimItemsTableConfirmed);
        self::assertSame([], $bag->conflictResolutions);
        self::assertSame([], $bag->conflictsAcknowledged);
        self::assertSame(serialize(self::EMPTY), serialize($bag->toArray()));
    }

    public function testAPartialArrayIsNormalizedToTheFullShape(): void
    {
        $debtors = new Step2DebtorsData([new Step2DebtorEntry(personType: PersonType::PJ, name: 'Debitor SRL', cui: '14186770')]);
        $bag = WizardBag::fromArray(['documentIds' => [3, 4], 'debtors' => $debtors]);

        self::assertSame([3, 4], $bag->documentIds);
        self::assertSame($debtors, $bag->debtors);
        self::assertSame([...self::EMPTY, 'documentIds' => [3, 4], 'debtors' => $debtors], $bag->toArray());
        self::assertSame(array_keys(self::EMPTY), array_keys($bag->toArray()));
    }

    public function testValuesOfTheWrongTypeAreDropped(): void
    {
        $bag = WizardBag::fromArray([
            'documentIds' => 'not-a-list',
            'creditor' => 'Cedent SRL',
            'debtors' => new Step1CreditorData(name: 'wrong DTO'),
            'debtorBeforePick' => ['name' => 'array'],
            'claim' => new \stdClass(),
            'claimItems' => 'rows',
            'conflictResolutions' => 'none',
            'conflictsAcknowledged' => 'none',
        ]);

        self::assertSame([], $bag->documentIds);
        self::assertNull($bag->creditor);
        self::assertNull($bag->debtors);
        self::assertNull($bag->debtorBeforePick);
        self::assertNull($bag->claim);
        self::assertNull($bag->claimItems);
        self::assertSame([], $bag->conflictResolutions);
        self::assertSame([], $bag->conflictsAcknowledged);
        self::assertSame(serialize(self::EMPTY), serialize($bag->toArray()));
    }

    public function testUnknownKeysAreNotCarried(): void
    {
        $bag = WizardBag::fromArray(['documentIds' => [1], 'somethingElse' => 'x', 'step' => 2]);

        self::assertArrayNotHasKey('somethingElse', $bag->toArray());
        self::assertArrayNotHasKey('step', $bag->toArray());
        self::assertSame(array_keys(self::EMPTY), array_keys($bag->toArray()));
    }

    public function testDocumentIdsAreCastToIntegersAndDeduplicated(): void
    {
        $bag = WizardBag::fromArray(['documentIds' => ['7', 7, 8, '8', 9.0]]);

        self::assertSame([7, 8, 9], $bag->documentIds);
    }

    public function testClaimItemsKeepOnlyRowsAndAreReindexed(): void
    {
        $row = new ClaimItemRow('F-1', 100.0);
        $other = new ClaimItemRow('F-2', 50.0);
        $bag = WizardBag::fromArray(['claimItems' => [5 => 'text', 9 => $row, 11 => null, 12 => $other]]);

        self::assertSame([$row, $other], $bag->claimItems);
    }

    public function testAnEmptyClaimItemsListStaysAnEmptyList(): void
    {
        self::assertSame([], WizardBag::fromArray(['claimItems' => []])->claimItems);
    }

    public function testFlagsAreCastToBool(): void
    {
        $bag = WizardBag::fromArray([
            'debtorLibraryRecheck' => 1,
            'creditorLibraryRecheck' => 'yes',
            'claimItemsTableConfirmed' => '0',
        ]);

        self::assertTrue($bag->debtorLibraryRecheck);
        self::assertTrue($bag->creditorLibraryRecheck);
        self::assertFalse($bag->claimItemsTableConfirmed);
    }

    public function testConflictResolutionsKeepOnlyResolutionsUnderStringKeys(): void
    {
        $resolution = new ConflictResolution('creditor.name', ConflictScope::CREDITOR, 'name', null, 'Cedent SRL', 1);
        $bag = WizardBag::fromArray(['conflictResolutions' => [
            'creditor.name' => $resolution,
            'claim.amount' => ['value' => 10],
            0 => $resolution,
        ]]);

        self::assertSame(['creditor.name' => $resolution], $bag->conflictResolutions);
    }

    public function testAnAcknowledgementWithoutFingerprintOrDateIsIgnored(): void
    {
        $bag = WizardBag::fromArray(['conflictsAcknowledged' => [
            'creditor' => ['at' => '2026-11-03T10:00:00+00:00'],
            'debtors' => ['fingerprint' => 'abc'],
            'claim' => ['fingerprint' => 'def', 'at' => '2026-11-03T10:00:00+00:00', 'extra' => 'dropped'],
            0 => ['fingerprint' => 'ghi', 'at' => '2026-11-03T10:00:00+00:00'],
            'other' => 'text',
        ]]);

        self::assertSame(
            ['claim' => ['fingerprint' => 'def', 'at' => '2026-11-03T10:00:00+00:00']],
            $bag->conflictsAcknowledged,
        );
    }

    public function testRoundTripOfANormalizedArrayIsByteForByteIdentical(): void
    {
        $original = $this->fullArray();

        self::assertSame(serialize($original), serialize(WizardBag::fromArray($original)->toArray()));
    }

    public function testRoundTripKeepsTheSameDtoInstances(): void
    {
        $original = $this->fullArray();
        $bag = WizardBag::fromArray($original);

        self::assertSame($original['creditor'], $bag->creditor);
        self::assertSame($original['debtors'], $bag->debtors);
        self::assertSame($original['debtorBeforePick'], $bag->debtorBeforePick);
        self::assertSame($original['claim'], $bag->claim);
        self::assertSame($original['claimItems'][0], $bag->claimItems[0]);
        self::assertSame($original['conflictResolutions']['creditor.name'], $bag->conflictResolutions['creditor.name']);
    }

    public function testPropertiesAreMutableInPlace(): void
    {
        $bag = new WizardBag();
        $bag->documentIds[] = 4;
        $bag->conflictsAcknowledged['claim'] = ['fingerprint' => 'x', 'at' => 'now'];
        unset($bag->conflictsAcknowledged['claim']);

        self::assertSame([4], $bag->toArray()['documentIds']);
        self::assertSame([], $bag->toArray()['conflictsAcknowledged']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fullArray(): array
    {
        $entry = new Step2DebtorEntry(personType: PersonType::PJ, name: 'Debitor SRL', cui: '14186770', autoFilled: ['name']);

        return [
            'documentIds' => [1, 2],
            'creditor' => new Step1CreditorData(personType: PersonType::PJ, name: 'Cedent SRL', cui: 'RO15193236', autoFilled: ['name']),
            'debtors' => new Step2DebtorsData([$entry]),
            'debtorBeforePick' => new Step2DebtorsData([new Step2DebtorEntry(personType: PersonType::PJ, name: 'Inainte SRL')]),
            'debtorLibraryRecheck' => true,
            'creditorLibraryRecheck' => false,
            'claim' => new Step3ClaimData(amount: 1500.0, currency: 'RON', dueDate: new \DateTimeImmutable('2026-01-15')),
            'claimItems' => [new ClaimItemRow('F-1', 1000.0, sourceDocumentId: 1), new ClaimItemRow('F-2', 500.0, sourceDocumentId: 2)],
            'claimItemsTableConfirmed' => true,
            'conflictResolutions' => [
                'creditor.name' => new ConflictResolution('creditor.name', ConflictScope::CREDITOR, 'name', null, 'Cedent SRL', 0, 1),
            ],
            'conflictsAcknowledged' => [
                'claim' => ['fingerprint' => 'abc', 'at' => '2026-11-03T10:00:00+00:00'],
            ],
        ];
    }
}
