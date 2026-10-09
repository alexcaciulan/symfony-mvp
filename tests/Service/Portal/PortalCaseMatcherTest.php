<?php

declare(strict_types=1);

namespace App\Tests\Service\Portal;

use App\Entity\CaseStatusHistory;
use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\LegalCaseDebtor;
use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\PortalCaseMatchSource;
use App\Service\Portal\PortalCaseMatcher;
use App\Service\Portal\PortalJustClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PortalCaseMatcherTest extends TestCase
{
    /**
     * Fake PortalJustClient returning canned results keyed by party name, so the
     * matcher's scoring/dedup logic is tested without any SOAP I/O.
     *
     * @param array<string, array<int, array<string, mixed>>> $byName
     */
    private function matcherReturning(array $byName): PortalCaseMatcher
    {
        $fakeClient = new class($byName) extends PortalJustClient {
            /** @param array<string, array<int, array<string, mixed>>> $byName */
            public function __construct(private array $byName)
            {
                parent::__construct(new NullLogger());
            }

            public function searchByParty(
                string $partyName,
                string $institutionCode,
                ?\DateTimeInterface $from = null,
                ?\DateTimeInterface $to = null,
            ): array {
                return $this->byName[$partyName] ?? [];
            }
        };

        return new PortalCaseMatcher($fakeClient, new NullLogger());
    }

    /**
     * @param array<int, array{nume: string, calitateParte: string}> $parti
     *
     * @return array<string, mixed>
     */
    private function dosar(string $numar, array $parti, ?string $obiect = null, ?string $data = null): array
    {
        return [
            'numar' => $numar,
            'data' => $data,
            'institutie' => 'JudecatoriaCLUJNAPOCA',
            'departament' => 'Civil',
            'categorieCaz' => null,
            'stadiuProcesual' => 'Fond',
            'obiect' => $obiect,
            'dataModificare' => '2026-03-01',
            'parti' => $parti,
            'sedinte' => [],
            'caiAtac' => [],
        ];
    }

    /** A case whose payment order request was generated today, as the workflow records it. */
    private function caseWith(string $creditorName, string ...$debtorNames): LegalCase
    {
        $case = $this->caseWithoutRequest($creditorName, ...$debtorNames);
        $generated = new CaseStatusHistory();
        $generated->setLegalCase($case);
        $generated->setOldStatus(CaseStatus::SOMATIE_TRIMISA->value);
        $generated->setNewStatus(CaseStatus::CERERE_GENERATA->value);
        $case->getStatusHistory()->add($generated);

        return $case;
    }

    private function caseWithoutRequest(string $creditorName, string ...$debtorNames): LegalCase
    {
        $court = new Court();
        $court->setPortalCode('JudecatoriaCLUJNAPOCA');

        $creditor = new Creditor();
        $creditor->setName($creditorName);

        $case = new LegalCase();
        $case->setCourt($court);
        $case->setCreditor($creditor);

        foreach ($debtorNames as $name) {
            $debtor = new Debtor();
            $debtor->setName($name);
            $case->addDebtor(new LegalCaseDebtor($debtor));
        }

        return $case;
    }

    /** @return array<int, array{nume: string, calitateParte: string}> */
    private function ourParties(): array
    {
        return [
            ['nume' => 'CREDITOR SRL', 'calitateParte' => 'Creditor'],
            ['nume' => 'DEBITOR SRL', 'calitateParte' => 'Debitor'],
        ];
    }

    public function testAnEarlierCaseOfTheSamePartiesIsListedLastAndOursIsProposed(): void
    {
        $case = $this->caseWith('SC Creditor SRL', 'SC Debitor SRL');
        $matcher = $this->matcherReturning(['DEBITOR' => [
            $this->dosar('100/302/2024', $this->ourParties(), 'ordonanță de plată', '2024-05-01T10:00:00'),
            $this->dosar('4521/302/2026', $this->ourParties(), 'ordonanță de plată', (new \DateTimeImmutable())->format('Y-m-d\TH:i:s')),
        ]]);

        $suggestions = $matcher->findCandidates($case);

        $this->assertSame(['4521/302/2026', '100/302/2024'], array_map(static fn ($s) => $s->numar, $suggestions));
        $this->assertTrue($suggestions[0]->isHighConfidence, 'the earlier case does not tie with ours');
        $this->assertFalse($suggestions[1]->isHighConfidence);
        $this->assertTrue($suggestions[1]->registeredBeforeRequest);
        $this->assertSame('2024-05-01T10:00:00', $suggestions[1]->dataInregistrare);
    }

    public function testAnEarlierCaseAloneIsNotProposed(): void
    {
        // Ours is not on the portal yet: the earlier payment order between the
        // same parties is the only match, and must not pass for ours.
        $case = $this->caseWith('SC Creditor SRL', 'SC Debitor SRL');
        $matcher = $this->matcherReturning(['DEBITOR' => [
            $this->dosar('100/302/2024', $this->ourParties(), 'ordonanță de plată', '2024-05-01T10:00:00'),
        ]]);

        $suggestions = $matcher->findCandidates($case);

        $this->assertCount(1, $suggestions, 'still listed for the lawyer');
        $this->assertFalse($suggestions[0]->isHighConfidence);
        $this->assertTrue($suggestions[0]->registeredBeforeRequest);
    }

    public function testACaseRegisteredTheDayTheRequestWasGeneratedCanBeOurs(): void
    {
        $case = $this->caseWith('SC Creditor SRL', 'SC Debitor SRL');
        $matcher = $this->matcherReturning(['DEBITOR' => [
            $this->dosar('4521/302/2026', $this->ourParties(), 'ordonanță de plată', (new \DateTimeImmutable('today'))->format('Y-m-d') . 'T00:00:00'),
        ]]);

        $this->assertTrue($matcher->findCandidates($case)[0]->isHighConfidence);
    }

    public function testTheFilingDateStandsInForACaseWithoutRequestHistory(): void
    {
        $case = $this->caseWithoutRequest('SC Creditor SRL', 'SC Debitor SRL');
        $case->setFiledAt(new \DateTimeImmutable('2025-02-10'));
        $matcher = $this->matcherReturning(['DEBITOR' => [
            $this->dosar('100/302/2025', $this->ourParties(), 'ordonanță de plată', '2025-02-01T09:00:00'),
        ]]);

        $this->assertTrue($matcher->findCandidates($case)[0]->registeredBeforeRequest);
    }

    public function testNothingIsProposedBeforeTheRequestIsGenerated(): void
    {
        $case = $this->caseWithoutRequest('SC Creditor SRL', 'SC Debitor SRL');
        $matcher = $this->matcherReturning(['DEBITOR' => [
            $this->dosar('4521/302/2026', $this->ourParties(), 'ordonanță de plată', '2026-03-01T09:00:00'),
        ]]);

        $suggestions = $matcher->findCandidates($case);

        $this->assertFalse($suggestions[0]->isHighConfidence, 'no request, so no case can be ours yet');
        $this->assertFalse($suggestions[0]->registeredBeforeRequest, 'nothing to compare with, so not marked either');
    }

    public function testANumberSetAsideIsListedLastEvenWithTheBestScore(): void
    {
        $case = $this->caseWith('SC Creditor SRL', 'SC Debitor SRL');
        $case->proposePortalMatch('100/302/2026', PortalCaseMatchSource::AUTO);
        $case->dismissPortalProposal(null);
        $matcher = $this->matcherReturning(['DEBITOR' => [
            $this->dosar('100/302/2026', $this->ourParties(), 'ordonanță de plată'),
            $this->dosar('200/302/2026', [['nume' => 'DEBITOR SRL', 'calitateParte' => 'Debitor'], ['nume' => 'CREDITOR SRL', 'calitateParte' => 'Creditor']], 'Pretenții'),
        ]]);

        $suggestions = $matcher->findCandidates($case);

        $this->assertSame(['200/302/2026', '100/302/2026'], array_map(static fn ($s) => $s->numar, $suggestions));
        $this->assertTrue($suggestions[1]->wasDismissed);
        $this->assertFalse($suggestions[1]->isHighConfidence);
        $this->assertFalse($suggestions[0]->isHighConfidence, 'the one left has no payment order marker');
    }

    public function testReturnsEmptyWhenCourtHasNoPortalCode(): void
    {
        $case = $this->caseWith('SC Creditor SRL', 'SC Debitor SRL');
        $case->getCourt()->setPortalCode(null);

        $matcher = $this->matcherReturning([]);
        $this->assertSame([], $matcher->findCandidates($case));
    }

    public function testReturnsEmptyWhenNoDebtors(): void
    {
        $case = $this->caseWith('SC Creditor SRL');

        $matcher = $this->matcherReturning(['CREDITOR' => [$this->dosar('1/2/2026', [])]]);
        $this->assertSame([], $matcher->findCandidates($case));
    }

    public function testRanksFullMatchWithOpMarkerAsHighConfidence(): void
    {
        $case = $this->caseWith('SC Creditor SRL', 'SC Debitor SRL');

        $matcher = $this->matcherReturning([
            'DEBITOR' => [
                // Full match (both parties) + OP object → should rank first, high confidence.
                $this->dosar('4521/302/2026', [
                    ['nume' => 'CREDITOR SRL', 'calitateParte' => 'Creditor'],
                    ['nume' => 'DEBITOR SRL', 'calitateParte' => 'Debitor'],
                ], 'ordonanță de plată'),
                // Only the debtor matches, no OP marker → lower score.
                $this->dosar('9999/302/2026', [
                    ['nume' => 'DEBITOR SRL', 'calitateParte' => 'Pârât'],
                    ['nume' => 'ALT RECLAMANT SA', 'calitateParte' => 'Reclamant'],
                ], 'Pretenții'),
            ],
        ]);

        $suggestions = $matcher->findCandidates($case);

        // The debtor's case with another claimant is not offered once one of
        // its cases names our creditor too.
        $this->assertCount(1, $suggestions);
        $this->assertSame('4521/302/2026', $suggestions[0]->numar);
        $this->assertTrue($suggestions[0]->isHighConfidence);
        $this->assertContains('SC Creditor SRL', $suggestions[0]->matchedPartyNames);
        $this->assertContains('SC Debitor SRL', $suggestions[0]->matchedPartyNames);
    }

    public function testDedupesSameCaseAcrossMultipleDebtorSearches(): void
    {
        $case = $this->caseWith('SC Creditor SRL', 'SC Debitor Unu SRL', 'SC Debitor Doi SRL');

        $shared = $this->dosar('100/302/2026', [
            ['nume' => 'DEBITOR UNU SRL', 'calitateParte' => 'Debitor'],
            ['nume' => 'DEBITOR DOI SRL', 'calitateParte' => 'Debitor'],
        ], 'ordonanță de plată');

        $matcher = $this->matcherReturning([
            'DEBITOR UNU' => [$shared],
            'DEBITOR DOI' => [$shared],
        ]);

        $suggestions = $matcher->findCandidates($case);

        $this->assertCount(1, $suggestions);
        $this->assertSame('100/302/2026', $suggestions[0]->numar);
    }

    public function testTwoEqualFullMatchesAreNotHighConfidence(): void
    {
        $case = $this->caseWith('SC Creditor SRL', 'SC Debitor SRL');

        $parti = [
            ['nume' => 'CREDITOR SRL', 'calitateParte' => 'Creditor'],
            ['nume' => 'DEBITOR SRL', 'calitateParte' => 'Debitor'],
        ];

        $matcher = $this->matcherReturning([
            'DEBITOR' => [
                $this->dosar('1/302/2026', $parti, 'ordonanță de plată'),
                $this->dosar('2/302/2026', $parti, 'ordonanță de plată'),
            ],
        ]);

        $suggestions = $matcher->findCandidates($case);

        $this->assertCount(2, $suggestions);
        $this->assertFalse($suggestions[0]->isHighConfidence);
        $this->assertFalse($suggestions[1]->isHighConfidence);
    }

    public function testTheDebtorIsSearchedWithoutLegalFormPunctuationOrDiacritics(): void
    {
        // "HEALTHU WORLDWIDE S.R.L." finds nothing on the portal, which stores
        // "SRL"; "Ș" with a comma finds nothing either. Case 2 of the lawyer
        // review: https://portal.just.ro/211/SitePages/Dosar.aspx?id_dosar=21100000000527015
        $case = $this->caseWith('EXPERT SERVICE SUPPLY S.R.L.', 'HEALTHU WORLDWIDE S.R.L.', 'ȘTEFĂNESCU & FIII S.R.L.');
        $matcher = $this->matcherReturning([
            'HEALTHU WORLDWIDE' => [$this->dosar('19883/211/2025', [
                ['nume' => 'EXPERT SERVICE SUPPLY SRL', 'calitateParte' => 'Creditor'],
                ['nume' => 'HEALTHU WORLDWIDE SRL', 'calitateParte' => 'Debitor'],
            ], 'somaţie de plată')],
            'STEFANESCU FIII' => [],
        ]);

        $suggestions = $matcher->findCandidates($case);

        $this->assertCount(1, $suggestions);
        $this->assertSame('19883/211/2025', $suggestions[0]->numar);
    }

    public function testWithoutACaseNamingTheCreditorTheDebtorsCasesAreAllOffered(): void
    {
        $case = $this->caseWith('SC Creditor SRL', 'SC Debitor SRL');
        $matcher = $this->matcherReturning([
            'DEBITOR' => [
                $this->dosar('1/302/2026', [['nume' => 'DEBITOR SRL', 'calitateParte' => 'Pârât'], ['nume' => 'ALT SRL', 'calitateParte' => 'Reclamant']]),
                $this->dosar('2/302/2026', [['nume' => 'DEBITOR SRL', 'calitateParte' => 'Pârât'], ['nume' => 'TERT SA', 'calitateParte' => 'Reclamant']]),
            ],
        ]);

        $this->assertCount(2, $matcher->findCandidates($case));
    }

    public function testThePortalsSomatieDePlataObjectMarksAPaymentOrder(): void
    {
        // Real listing for case 2 of the lawyer review: the request filed in
        // 2023 is labelled "somaţie de plată" on portal.just.ro, next to the
        // debtor's annulment request in another case.
        $case = $this->caseWith('EXPERT SERVICE SUPPLY S.R.L.', 'HEALTHU WORLDWIDE S.R.L.');
        $matcher = $this->matcherReturning([
            'HEALTHU WORLDWIDE' => [
                $this->dosar('24697/211/2023', [
                    ['nume' => 'EXPERT SERVICE SUPPLY SRL', 'calitateParte' => 'Creditor'],
                    ['nume' => 'HEALTHU WORLDWIDE SRL', 'calitateParte' => 'Debitor'],
                ], 'somaţie de plată'),
                $this->dosar('8187/211/2025', [
                    ['nume' => 'EXPERT SERVICE SUPPLY SRL', 'calitateParte' => 'Creditor'],
                    ['nume' => 'HEALTHU WORLDWIDE SRL', 'calitateParte' => 'Debitor'],
                ], 'anulare somaţie de plată'),
            ],
        ]);

        $suggestions = $matcher->findCandidates($case);

        $this->assertSame('24697/211/2023', $suggestions[0]->numar);
        $this->assertTrue($suggestions[0]->isHighConfidence);
        $this->assertGreaterThan($suggestions[1]->score, $suggestions[0]->score, 'the annulment request is another case');
    }
}
