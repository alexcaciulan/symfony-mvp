<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\CaseDraft;
use App\Entity\User;
use App\Enum\PersonType;
use App\Service\Wizard\WizardBag;
use PHPUnit\Framework\TestCase;

final class CaseDraftTest extends TestCase
{
    public function testANewDraftStartsOnTheDocumentsStepWithNothingInIt(): void
    {
        $now = new \DateTimeImmutable('2026-11-03 10:00:00');
        $draft = new CaseDraft(new User(), $now);

        self::assertSame(CaseDraft::STEP_DOCUMENTS, $draft->getStepReached());
        self::assertSame(0, $draft->getDocumentsCount());
        self::assertNull($draft->getCreditorName());
        self::assertSame(1, $draft->getVersion());
        self::assertSame($now, $draft->getCreatedAt());
        self::assertSame($now, $draft->getLastActivityAt());
    }

    public function testTheStepReachedFollowsWhatTheBagHasSaved(): void
    {
        self::assertSame(CaseDraft::STEP_DOCUMENTS, CaseDraft::stepReachedFor(new WizardBag(documentIds: [1, 2])));
        self::assertSame(CaseDraft::STEP_DEBTOR, CaseDraft::stepReachedFor(new WizardBag(creditor: new Step1CreditorData(name: 'Cedent SRL'))));
        self::assertSame(CaseDraft::STEP_CLAIM, CaseDraft::stepReachedFor(new WizardBag(
            creditor: new Step1CreditorData(name: 'Cedent SRL'),
            debtors: new Step2DebtorsData([new Step2DebtorEntry(personType: PersonType::PJ, name: 'Debitor SRL')]),
        )));
        self::assertSame(CaseDraft::STEP_CONFIRMATION, CaseDraft::stepReachedFor(new WizardBag(
            creditor: new Step1CreditorData(name: 'Cedent SRL'),
            debtors: new Step2DebtorsData([new Step2DebtorEntry(personType: PersonType::PJ, name: 'Debitor SRL')]),
            claim: new Step3ClaimData(amount: 10.0),
        )));
    }

    public function testDenormalizingCopiesTheListColumnsFromTheBag(): void
    {
        $draft = new CaseDraft(new User(), new \DateTimeImmutable('2026-11-01 09:00:00'));
        $later = new \DateTimeImmutable('2026-11-03 10:00:00');
        $bag = new WizardBag(
            documentIds: [4, 5, 6],
            creditor: new Step1CreditorData(name: '  Cedent SRL  '),
        );

        $draft->denormalizeFrom($bag, $later);

        self::assertSame(CaseDraft::STEP_DEBTOR, $draft->getStepReached());
        self::assertSame(3, $draft->getDocumentsCount());
        self::assertSame('Cedent SRL', $draft->getCreditorName());
        self::assertSame($later, $draft->getUpdatedAt());
        self::assertSame($later, $draft->getLastActivityAt());
    }

    public function testABlankCreditorNameIsStoredAsNull(): void
    {
        $draft = new CaseDraft(new User());

        $draft->denormalizeFrom(new WizardBag(creditor: new Step1CreditorData(name: '   ')), new \DateTimeImmutable());

        self::assertNull($draft->getCreditorName());
    }

    public function testThePayloadReadsBackWhetherSetAsBytesOrAsAStream(): void
    {
        $draft = new CaseDraft(new User());
        $draft->setPayload('bytes', 1);
        self::assertSame('bytes', $draft->getPayload());
        self::assertSame(1, $draft->getPayloadVersion());

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'from a stream');
        (new \ReflectionProperty(CaseDraft::class, 'payload'))->setValue($draft, $stream);
        self::assertSame('from a stream', $draft->getPayload());
        self::assertSame('from a stream', $draft->getPayload(), 'a second read does not need the stream again');
    }

    public function testTouchMovesOnlyTheLastActivity(): void
    {
        $created = new \DateTimeImmutable('2026-11-01 09:00:00');
        $draft = new CaseDraft(new User(), $created);
        $later = new \DateTimeImmutable('2026-11-03 10:00:00');

        $draft->touch($later);

        self::assertSame($later, $draft->getLastActivityAt());
        self::assertSame($created, $draft->getUpdatedAt());
    }
}
