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
     * normalized + lowercased text). Full phrases only, so "ordonanță
     * prezidențială" (CPC art. 996) does not score as OP. The courts' case
     * system still files today's payment-order requests under the object
     * "somaţie de plată", the name of the procedure OUG 5/2001 had until 2013
     * (e.g. 24697/211/2023 at Judecătoria Cluj-Napoca); the search window never
     * reaches back to cases of that old procedure.
     */
    private const OP_MARKERS = ['ordonanta de plata', 'somatie de plata'];

    /**
     * Objects that name a payment order but are another case about it: the
     * debtor's annulment request is filed separately from the request itself.
     */
    private const OP_EXCLUDED_MARKERS = ['anulare'];

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
        $creditorName = null;
        $creditor = $case->getCreditor();
        if ($creditor !== null && $creditor->getName() !== '') {
            $creditorName = $creditor->getName();
            $ourParties[] = $creditorName;
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

        // The search runs on the debtor, so it brings every case the debtor has
        // at that court, most of them with other creditors. Where some name our
        // creditor too, those are the only ones worth the lawyer's attention;
        // where none does (a creditor spelled differently on the portal), all
        // stay, ranked, rather than nothing.
        if ($creditorName !== null) {
            $withCreditor = array_values(array_filter(
                $scored,
                static fn (array $entry): bool => in_array($creditorName, $entry['matched'], true),
            ));
            if ($withCreditor !== []) {
                $scored = $withCreditor;
            }
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
            $term = PartyNameNormalizer::searchTerm($name);
            if ($term === '') {
                continue;
            }
            try {
                $results = $this->portalClient->searchByParty($term, $portalCode, $from, $to);
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

        foreach (self::OP_EXCLUDED_MARKERS as $excluded) {
            if (str_contains($text, $excluded)) {
                return false;
            }
        }

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
