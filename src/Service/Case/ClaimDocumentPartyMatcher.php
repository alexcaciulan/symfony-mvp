<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Case\PartyMatchResult;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\PartyMatchStatus;
use App\Util\CuiNormalizer;

/**
 * Checks that an invoice uploaded onto a case was issued by its creditor to one
 * of its debtors. The parties of a case are fixed: an invoice between other
 * companies belongs in another case, and the only certain evidence of that is
 * a fiscal code on both sides that differs. A missing code is not evidence of
 * anything, so it only asks the lawyer to look at the names.
 */
final class ClaimDocumentPartyMatcher
{
    public function match(Document $document, LegalCase $case): PartyMatchResult
    {
        $extracted = $document->getExtractedData() ?? [];
        $creditor = is_array($extracted['creditor'] ?? null) ? $extracted['creditor'] : [];
        $debtor = is_array($extracted['debtor'] ?? null) ? $extracted['debtor'] : [];

        $creditorCui = ExtractedValue::string($creditor['cui'] ?? null);
        $creditorName = ExtractedValue::string($creditor['name'] ?? null);
        $debtorCui = ExtractedValue::string($debtor['cui'] ?? null);
        $debtorName = ExtractedValue::string($debtor['name'] ?? null);

        $caseCreditor = $case->getCreditor();
        $caseDebtorCuis = [];
        $caseDebtorNames = [];
        foreach ($case->getDebtors() as $link) {
            $caseDebtorCuis[] = $link->getCui();
            $caseDebtorNames[] = $link->getName();
        }

        return new PartyMatchResult(
            creditor: self::compare($creditorCui, [$caseCreditor?->getCui()]),
            debtor: self::compare($debtorCui, $caseDebtorCuis),
            extractedCreditorName: $creditorName,
            extractedCreditorCui: $creditorCui,
            extractedDebtorName: $debtorName,
            extractedDebtorCui: $debtorCui,
            creditorNameMatches: self::sameName($creditorName, [$caseCreditor?->getName()]),
            debtorNameMatches: self::sameName($debtorName, $caseDebtorNames),
        );
    }

    /** @param list<?string> $caseCuis */
    private static function compare(?string $extractedCui, array $caseCuis): PartyMatchStatus
    {
        $extracted = CuiNormalizer::normalize($extractedCui);
        if ($extracted === null) {
            return PartyMatchStatus::UNKNOWN;
        }
        $known = array_values(array_filter(array_map(CuiNormalizer::normalize(...), $caseCuis)));
        if ($known === []) {
            return PartyMatchStatus::UNKNOWN;
        }

        return in_array($extracted, $known, true) ? PartyMatchStatus::MATCH : PartyMatchStatus::MISMATCH;
    }

    /** @param list<?string> $caseNames */
    private static function sameName(?string $extractedName, array $caseNames): bool
    {
        $extracted = self::normalizeName($extractedName);
        if ($extracted === '') {
            return false;
        }
        foreach ($caseNames as $name) {
            $known = self::normalizeName($name);
            if ($known !== '' && ($known === $extracted || str_contains($known, $extracted) || str_contains($extracted, $known))) {
                return true;
            }
        }

        return false;
    }

    /** Lowercase, no diacritics, no legal-form words, no punctuation: what is left names the company. */
    private static function normalizeName(?string $name): string
    {
        if ($name === null) {
            return '';
        }
        $ascii = \Normalizer::normalize($name, \Normalizer::FORM_D);
        $ascii = preg_replace('/\p{Mn}+/u', '', $ascii === false ? $name : $ascii) ?? '';
        $lower = mb_strtolower($ascii);
        $lower = preg_replace('/[^a-z0-9]+/', ' ', $lower) ?? '';
        $words = array_filter(explode(' ', $lower), static fn (string $w): bool => $w !== '' && !in_array($w, ['s', 'c', 'sc', 'srl', 'sa', 'pfa', 'ii', 'if', 'r', 'l', 'a'], true));

        return implode(' ', $words);
    }
}
