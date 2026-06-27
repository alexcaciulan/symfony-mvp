<?php

declare(strict_types=1);

namespace App\Tests\Service\Portal;

use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\LegalCase;
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
    private function dosar(string $numar, array $parti, ?string $obiect = null): array
    {
        return [
            'numar' => $numar,
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

    private function caseWith(string $creditorName, string ...$debtorNames): LegalCase
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
            $case->addDebtor($debtor);
        }

        return $case;
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

        $matcher = $this->matcherReturning(['SC Creditor SRL' => [$this->dosar('1/2/2026', [])]]);
        $this->assertSame([], $matcher->findCandidates($case));
    }

    public function testRanksFullMatchWithOpMarkerAsHighConfidence(): void
    {
        $case = $this->caseWith('SC Creditor SRL', 'SC Debitor SRL');

        $matcher = $this->matcherReturning([
            'SC Debitor SRL' => [
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

        $this->assertCount(2, $suggestions);
        $this->assertSame('4521/302/2026', $suggestions[0]->numar);
        $this->assertTrue($suggestions[0]->isHighConfidence);
        $this->assertGreaterThan($suggestions[1]->score, $suggestions[0]->score);
        $this->assertFalse($suggestions[1]->isHighConfidence);
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
            'SC Debitor Unu SRL' => [$shared],
            'SC Debitor Doi SRL' => [$shared],
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
            'SC Debitor SRL' => [
                $this->dosar('1/302/2026', $parti, 'ordonanță de plată'),
                $this->dosar('2/302/2026', $parti, 'ordonanță de plată'),
            ],
        ]);

        $suggestions = $matcher->findCandidates($case);

        $this->assertCount(2, $suggestions);
        $this->assertFalse($suggestions[0]->isHighConfidence);
        $this->assertFalse($suggestions[1]->isHighConfidence);
    }
}
