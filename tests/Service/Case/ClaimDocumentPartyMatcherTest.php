<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\PartyMatchStatus;
use App\Enum\PersonType;
use App\Service\Case\ClaimDocumentPartyMatcher;
use PHPUnit\Framework\TestCase;

final class ClaimDocumentPartyMatcherTest extends TestCase
{
    private ClaimDocumentPartyMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new ClaimDocumentPartyMatcher();
    }

    public function testCodesSpelledDifferentlyStillMatchTheParties(): void
    {
        $result = $this->matcher->match(
            $this->document(['creditor' => ['cui' => 'ro 0012345678', 'name' => 'Alpha'], 'debtor' => ['cui' => '87654321', 'name' => 'Beta']]),
            $this->case('RO12345678', ['RO87654321']),
        );

        self::assertSame(PartyMatchStatus::MATCH, $result->creditor);
        self::assertSame(PartyMatchStatus::MATCH, $result->debtor);
        self::assertFalse($result->isCertainMismatch());
        self::assertSame([], $result->warningKeys());
    }

    public function testAnotherIssuerIsACertainMismatch(): void
    {
        $result = $this->matcher->match(
            $this->document(['creditor' => ['cui' => 'RO11223344', 'name' => 'SC Gamma Trade SRL'], 'debtor' => ['cui' => 'RO87654321']]),
            $this->case('RO12345678', ['RO87654321']),
        );

        self::assertSame(PartyMatchStatus::MISMATCH, $result->creditor);
        self::assertTrue($result->isCertainMismatch());
        self::assertSame('SC Gamma Trade SRL', $result->extractedCreditorName);
    }

    public function testAnotherRecipientIsACertainMismatchToo(): void
    {
        $result = $this->matcher->match(
            $this->document(['creditor' => ['cui' => 'RO12345678'], 'debtor' => ['cui' => 'RO 0099998888', 'name' => 'SC Delta Impex SRL']]),
            $this->case('RO12345678', ['RO87654321']),
        );

        self::assertSame(PartyMatchStatus::MATCH, $result->creditor);
        self::assertSame(PartyMatchStatus::MISMATCH, $result->debtor);
        self::assertTrue($result->isCertainMismatch(), 'A recipient that is none of the case debtors is as certain as another issuer.');
        self::assertSame('SC Delta Impex SRL', $result->extractedDebtorName);
        self::assertSame('RO 0099998888', $result->extractedDebtorCui);
        self::assertSame([], $result->warningKeys(), 'A mismatch is a refusal, not a warning.');
    }

    public function testADebtorOfTheCaseAmongSeveralMatches(): void
    {
        $result = $this->matcher->match(
            $this->document(['creditor' => ['cui' => 'RO12345678'], 'debtor' => ['cui' => 'RO55556666']]),
            $this->case('RO12345678', ['RO87654321', 'RO55556666']),
        );

        self::assertSame(PartyMatchStatus::MATCH, $result->debtor);
    }

    public function testAMissingCodeOnlyWarnsAndTheNameIsCompared(): void
    {
        $matchingName = $this->matcher->match(
            $this->document(['creditor' => ['name' => 'S.C. Alpha Construct S.R.L.'], 'debtor' => ['cui' => 'RO87654321']]),
            $this->case('RO12345678', ['RO87654321'], creditorName: 'SC ALPHA CONSTRUCT SRL'),
        );
        self::assertSame(PartyMatchStatus::UNKNOWN, $matchingName->creditor);
        self::assertFalse($matchingName->isCertainMismatch());
        self::assertTrue($matchingName->creditorNameMatches);
        self::assertSame([], $matchingName->warningKeys());

        $otherName = $this->matcher->match(
            $this->document(['creditor' => ['name' => 'Gamma Trade'], 'debtor' => ['cui' => 'RO87654321']]),
            $this->case('RO12345678', ['RO87654321'], creditorName: 'SC Alpha Construct SRL'),
        );
        self::assertSame(PartyMatchStatus::UNKNOWN, $otherName->creditor);
        self::assertFalse($otherName->isCertainMismatch(), 'Without a code nothing is certain.');
        self::assertSame(['case.claim.edit.warning.creditor_unverified'], $otherName->warningKeys());
    }

    public function testACaseWithoutCodesCannotRefuseAnything(): void
    {
        $result = $this->matcher->match(
            $this->document(['creditor' => ['cui' => 'RO11223344'], 'debtor' => ['cui' => 'RO99998888']]),
            $this->case(null, [null]),
        );

        self::assertSame(PartyMatchStatus::UNKNOWN, $result->creditor);
        self::assertSame(PartyMatchStatus::UNKNOWN, $result->debtor);
        self::assertFalse($result->isCertainMismatch());
    }

    public function testNoExtractionMeansNothingToCompare(): void
    {
        $result = $this->matcher->match($this->document(null), $this->case('RO12345678', ['RO87654321']));

        self::assertSame(PartyMatchStatus::UNKNOWN, $result->creditor);
        self::assertSame(PartyMatchStatus::UNKNOWN, $result->debtor);
        self::assertCount(2, $result->warningKeys());
    }

    /** @param array<string, mixed>|null $extracted */
    private function document(?array $extracted): Document
    {
        $document = new Document();
        $document->setExtractedData($extracted);

        return $document;
    }

    /** @param list<?string> $debtorCuis */
    private function case(?string $creditorCui, array $debtorCuis, string $creditorName = 'SC Alpha Construct SRL'): LegalCase
    {
        $user = new User();
        $creditor = new Creditor();
        $creditor->setUser($user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName($creditorName);
        $creditor->setAddress('Str. Test 1');
        $creditor->setCui($creditorCui);

        $case = new LegalCase();
        $case->setUser($user);
        $case->setCreditor($creditor);
        foreach ($debtorCuis as $i => $cui) {
            $debtor = new Debtor();
            $debtor->setUser($user);
            $debtor->setPersonType(PersonType::PJ);
            $debtor->setName('SC Beta Logistic ' . $i . ' SRL');
            $debtor->setAddress('Str. Test 2');
            $debtor->setCui($cui);
            $case->addDebtor(new LegalCaseDebtor($debtor));
        }

        return $case;
    }
}
