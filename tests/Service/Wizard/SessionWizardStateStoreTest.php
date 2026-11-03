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
use App\Service\Wizard\SessionWizardStateStore;
use App\Service\Wizard\WizardBag;
use App\Service\Wizard\WizardStateStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SessionWizardStateStoreTest extends TestCase
{
    private WizardStateStore $store;

    protected function setUp(): void
    {
        // The app wires the interface to the draft store; this one has no
        // dependencies and is exercised on its own contract.
        $this->store = new SessionWizardStateStore();
    }

    public function testDetachLeavesAnEmptyBagLikeReset(): void
    {
        $session = $this->session();
        $this->store->save($session, new WizardBag(documentIds: [1]));

        $this->store->detach($session);

        self::assertSame([], $this->store->load($session)->documentIds);
    }

    public function testAnEmptySessionLoadsAnEmptyBag(): void
    {
        $bag = $this->store->load($this->session());

        self::assertSame(serialize((new WizardBag())->toArray()), serialize($bag->toArray()));
    }

    public function testANonArrayValueUnderTheKeyLoadsAnEmptyBag(): void
    {
        $session = $this->session();
        $session->set(SessionWizardStateStore::SESSION_KEY, 'garbage');

        self::assertSame([], $this->store->load($session)->documentIds);
    }

    public function testSaveStoresThePlainArrayUnderTheWizardKey(): void
    {
        $session = $this->session();
        $bag = new WizardBag(documentIds: [1, 2]);

        $this->store->save($session, $bag);

        $stored = $session->get(SessionWizardStateStore::SESSION_KEY);
        self::assertIsArray($stored);
        self::assertSame([1, 2], $stored['documentIds']);
        self::assertSame(serialize($bag->toArray()), serialize($stored));
    }

    public function testRoundTripWithDtosKeepsTheInstancesAndTheSerialization(): void
    {
        $session = $this->session();
        $creditor = new Step1CreditorData(personType: PersonType::PJ, name: 'Cedent SRL', cui: 'RO15193236');
        $debtors = new Step2DebtorsData([new Step2DebtorEntry(personType: PersonType::PJ, name: 'Debitor SRL', cui: '14186770')]);
        $claim = new Step3ClaimData(amount: 1500.0, dueDate: new \DateTimeImmutable('2026-01-15'));
        $rows = [new ClaimItemRow('F-1', 1500.0, sourceDocumentId: 1)];
        $resolution = new ConflictResolution('creditor.name', ConflictScope::CREDITOR, 'name', null, 'Cedent SRL', 0, 1);
        $bag = new WizardBag(
            documentIds: [1],
            creditor: $creditor,
            debtors: $debtors,
            claim: $claim,
            claimItems: $rows,
            conflictResolutions: ['creditor.name' => $resolution],
            claimItemsTableConfirmed: true,
            conflictsAcknowledged: ['claim' => ['fingerprint' => 'abc', 'at' => '2026-11-03T10:00:00+00:00']],
        );

        $this->store->save($session, $bag);
        $loaded = $this->store->load($session);

        self::assertSame($creditor, $loaded->creditor);
        self::assertSame($debtors, $loaded->debtors);
        self::assertSame($claim, $loaded->claim);
        self::assertSame($rows, $loaded->claimItems);
        self::assertSame(['creditor.name' => $resolution], $loaded->conflictResolutions);
        self::assertTrue($loaded->claimItemsTableConfirmed);
        self::assertSame(serialize($bag->toArray()), serialize($loaded->toArray()));
    }

    public function testAPartialPayloadWrittenDirectlyIsNormalizedOnLoad(): void
    {
        $session = $this->session();
        $debtors = new Step2DebtorsData([new Step2DebtorEntry(personType: PersonType::PJ, name: 'Debitor SRL')]);
        $row = new ClaimItemRow('F-1', 10.0);
        $resolution = new ConflictResolution('debtor.name', ConflictScope::DEBTOR, 'name', null, 'Debitor SRL', 0, 3);
        $session->set(SessionWizardStateStore::SESSION_KEY, [
            'documentIds' => ['3', 3, 4],
            'debtors' => $debtors,
            'creditor' => 'not a dto',
            'claimItems' => ['x', $row],
            'claimItemsTableConfirmed' => 1,
            'conflictResolutions' => ['debtor.name' => $resolution, 0 => $resolution, 'claim.amount' => 'text'],
            'conflictsAcknowledged' => [
                'creditor' => ['fingerprint' => 'only'],
                'debtors' => ['fingerprint' => 'abc', 'at' => '2026-11-03T10:00:00+00:00', 'extra' => 'dropped'],
            ],
            'unknown' => true,
        ]);

        $bag = $this->store->load($session);

        self::assertSame([3, 4], $bag->documentIds);
        self::assertSame($debtors, $bag->debtors);
        self::assertNull($bag->creditor);
        self::assertSame([$row], $bag->claimItems);
        self::assertSame(['debtor.name' => $resolution], $bag->conflictResolutions);
        self::assertSame(['debtors' => ['fingerprint' => 'abc', 'at' => '2026-11-03T10:00:00+00:00']], $bag->conflictsAcknowledged);
        // The whole normalized shape, in the stored key order, as the controller
        // used to build it from the same payload.
        self::assertSame(serialize([
            'documentIds' => [3, 4],
            'creditor' => null,
            'debtors' => $debtors,
            'debtorBeforePick' => null,
            'debtorLibraryRecheck' => false,
            'creditorLibraryRecheck' => false,
            'claim' => null,
            'claimItems' => [$row],
            'claimItemsTableConfirmed' => true,
            'conflictResolutions' => ['debtor.name' => $resolution],
            'conflictsAcknowledged' => ['debtors' => ['fingerprint' => 'abc', 'at' => '2026-11-03T10:00:00+00:00']],
        ]), serialize($bag->toArray()));
    }

    public function testLoadDoesNotWriteBackToTheSession(): void
    {
        $session = $this->session();
        $session->set(SessionWizardStateStore::SESSION_KEY, ['documentIds' => ['3', 3]]);
        $before = serialize($session->get(SessionWizardStateStore::SESSION_KEY));

        $this->store->load($session);

        self::assertSame($before, serialize($session->get(SessionWizardStateStore::SESSION_KEY)));
    }

    public function testResetLeavesAnEmptyBagInTheSession(): void
    {
        $session = $this->session();
        $this->store->save($session, new WizardBag(documentIds: [1, 2], claimItemsTableConfirmed: true));

        $this->store->reset($session);

        self::assertSame(serialize((new WizardBag())->toArray()), serialize($session->get(SessionWizardStateStore::SESSION_KEY)));
        self::assertSame([], $this->store->load($session)->documentIds);
    }

    private function session(): SessionInterface
    {
        return new Session(new MockArraySessionStorage());
    }
}
