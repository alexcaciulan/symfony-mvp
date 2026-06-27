<?php

declare(strict_types=1);

namespace App\Service\Portal;

use App\Entity\LegalCase;
use App\Service\Portal\Dto\PortalCaseSuggestion;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Auto-discovers a case on portal.just.ro: searches by debtor name within the
 * competent court, scores by party matches + OP-object marker, returns ranked
 * suggestions. Never auto-activates (portal exposes only names, no CUI/CNP) — the
 * lawyer confirms one with a single click.
 */
final class PortalCaseMatcher
{
    private const MAX_SUGGESTIONS = 8;

    /**
     * Object/category markers for the payment-order procedure (matched on the
     * normalized + lowercased text). Full phrase "ordonanta de plata" only, to
     * avoid scoring "ordonanță prezidențială" (CPC art. 996) or the abrogated
     * "somație de plată" (OUG 5/2001) as OP.
     */
    private const OP_MARKERS = ['ordonanta de plata'];

    // Lower bound of the portal date window, relative to case creation. The portal
    // filters on the REGISTRATION date (not last-modified), and a case may be added
    // long after filing, so the window is generous (1y tolerates retroactive entry).
    private const WINDOW_BUFFER = '-1 year';

    public function __construct(
        private readonly PortalJustClient $portalClient,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return PortalCaseSuggestion[] Ranked desc by match score, capped at MAX_SUGGESTIONS
     */
    public function findCandidates(LegalCase $case): array
    {
        $court = $case->getCourt();
        $portalCode = $court?->getPortalCode();
        if ($portalCode === null || $portalCode === '') {
            return [];
        }

        $ourParties = [];
        $creditor = $case->getCreditor();
        if ($creditor !== null && $creditor->getName() !== '') {
            $ourParties[] = $creditor->getName();
        }

        $debtorNames = [];
        foreach ($case->getDebtors() as $debtor) {
            if ($debtor->getName() !== '') {
                $debtorNames[] = $debtor->getName();
                $ourParties[] = $debtor->getName();
            }
        }

        if ($debtorNames === []) {
            return [];
        }

        $dosare = $this->fetchByDebtors($case, $debtorNames, $portalCode);
        if ($dosare === []) {
            return [];
        }

        $scored = [];
        foreach ($dosare as $dosar) {
            $scored[] = $this->scoreDosar($dosar, $ourParties);
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score']
                ?: strcmp((string) ($b['dosar']['dataModificare'] ?? ''), (string) ($a['dosar']['dataModificare'] ?? ''));
        });

        return $this->buildSuggestions($scored);
    }

    /**
     * Search the portal by each debtor name, scoped to the court and bounded by a
     * date window. Merges results and dedupes by case number.
     *
     * @param string[] $debtorNames
     *
     * @return array<string, array<string, mixed>> Keyed by case number
     */
    private function fetchByDebtors(LegalCase $case, array $debtorNames, string $portalCode): array
    {
        $from = $case->getCreatedAt()->modify(self::WINDOW_BUFFER) ?: new \DateTimeImmutable(self::WINDOW_BUFFER);
        $to = new \DateTimeImmutable();

        // One SOAP call per debtor (capped at 5 by the wizard). Each failure is
        // swallowed so a slow/partial portal does not abort the whole discovery;
        // the portal_search rate limiter (10/h) bounds abuse.
        $byNumar = [];
        foreach ($debtorNames as $name) {
            try {
                $results = $this->portalClient->searchByParty($name, $portalCode, $from, $to);
            } catch (PortalJustException $e) {
                // Do not log the party name: it may be a natural-person debtor
                // (GDPR art. 5(1)(f)). The case id + error suffice for triage.
                $this->logger->error('Portal party search failed', [
                    'caseId' => $case->getId(),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($results as $dosar) {
                $numar = $dosar['numar'] ?? null;
                if (is_string($numar) && $numar !== '') {
                    $byNumar[$numar] = $dosar;
                }
            }
        }

        return $byNumar;
    }

    /**
     * @param array<string, mixed> $dosar
     * @param string[]             $ourParties
     *
     * @return array{dosar: array<string, mixed>, score: int, matched: string[], allMatched: bool, opMarker: bool}
     */
    private function scoreDosar(array $dosar, array $ourParties): array
    {
        $portalNames = [];
        foreach (($dosar['parti'] ?? []) as $parte) {
            $nume = $parte['nume'] ?? null;
            if (is_string($nume) && $nume !== '') {
                $portalNames[] = $nume;
            }
        }

        $matched = [];
        foreach ($ourParties as $ours) {
            foreach ($portalNames as $portalName) {
                if (PartyNameNormalizer::matches($ours, $portalName)) {
                    $matched[] = $ours;
                    break;
                }
            }
        }

        $opMarker = $this->hasOpMarker((string) ($dosar['obiect'] ?? '') . ' ' . (string) ($dosar['categorieCaz'] ?? ''));

        $score = count($matched) + ($opMarker ? 1 : 0);
        $allMatched = $ourParties !== [] && count($matched) === count($ourParties);

        return [
            'dosar' => $dosar,
            'score' => $score,
            'matched' => $matched,
            'allMatched' => $allMatched,
            'opMarker' => $opMarker,
        ];
    }

    /**
     * @param array<int, array{dosar: array<string, mixed>, score: int, matched: string[], allMatched: bool, opMarker: bool}> $scored
     *
     * @return PortalCaseSuggestion[]
     */
    private function buildSuggestions(array $scored): array
    {
        $scored = array_slice($scored, 0, self::MAX_SUGGESTIONS);

        $suggestions = [];
        foreach ($scored as $i => $entry) {
            $dosar = $entry['dosar'];

            // High confidence: all our parties matched + OP marker present, and the
            // top candidate is clearly dominant (alone or strictly ahead of #2).
            $highConfidence = $i === 0
                && $entry['allMatched']
                && $entry['opMarker']
                && (count($scored) === 1 || $entry['score'] > $scored[1]['score']);

            $suggestions[] = new PortalCaseSuggestion(
                numar: (string) $dosar['numar'],
                institutie: $this->str($dosar['institutie'] ?? null),
                obiect: $this->str($dosar['obiect'] ?? null),
                stadiuProcesual: $this->str($dosar['stadiuProcesual'] ?? null),
                dataModificare: $this->str($dosar['dataModificare'] ?? null),
                parti: $dosar['parti'] ?? [],
                score: $entry['score'],
                matchedPartyNames: array_values(array_unique($entry['matched'])),
                isHighConfidence: $highConfidence,
            );
        }

        return $suggestions;
    }

    private function hasOpMarker(string $text): bool
    {
        $text = PartyNameNormalizer::normalize($text);
        $text = mb_strtolower($text);

        foreach (self::OP_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
