<?php

namespace App\Service\Validation;

use App\DTO\Validation\AdmissibilityIssue;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Enum\AnafStatus;
use App\Enum\IssueSeverity;
use App\Enum\PersonType;

/**
 * Validează admisibilitatea unei cereri de ordonanță de plată față de fiecare debitor
 * (CPC art. 1014 + Legea 85/2014).
 *
 * Returnează lista de issue-uri (ERROR / WARNING) pe care wizard-ul (Pas 3.x)
 * și PDF generator-ul (Pas 5.x) le consumă pentru a bloca / preveni acțiuni inadmisibile.
 *
 * Regulile (post-N4 din 2026-05-09 — verificare BPI obligatorie):
 *   1. PJ + anafStatus = RADIAT          → ERROR  OP_BLOCKED_DEREGISTERED
 *   2. PJ + inInsolvency = true          → ERROR  OP_BLOCKED_INSOLVENCY        (fail-fast)
 *   3. PJ + anafStatus = INACTIV         → WARNING OP_DEFENDANT_FISCALLY_INACTIVE
 *   4. PJ + anafStatus IS NULL           → WARNING OP_ANAF_NOT_VERIFIED         (exclude rule 5)
 *   5. PJ + anafCheckedAt > 30 zile      → WARNING OP_ANAF_STALE
 *   6. PJ + insolvencyCheckedAt IS NULL  → ERROR  OP_INSOLVENCY_NOT_VERIFIED   (N4: era WARNING)
 *   7. PJ + insolvencyCheckedAt > 7 zile → ERROR  OP_INSOLVENCY_STALE          (N4 nou)
 *   8. PF                                → WARNING OP_PF_BIPF_MANUAL_CHECK     (memento manual L 151/2015)
 *
 * Regulile 1+2 sunt fail-fast per debitor (un debitor blocat nu mai are sens să fie verificat BPI).
 * Regulile 6+7 se exclud reciproc (null vs vechi).
 */
final class OpAdmissibilityValidator
{
    /**
     * Prag intern de prudență pentru "vechime ANAF acceptabilă" — NU este un termen legal.
     * Decizie de produs: peste 30 zile, statusul fiscal poate fi învechit. Reverificare recomandată.
     */
    private const ANAF_STALE_DAYS = 30;

    /**
     * Prag intern de prudență pentru "vechime verificare BPI acceptabilă" — NU este un termen legal.
     * Legea 85/2014 nu fixează valabilitatea unei verificări BPI. Decizie de produs: insolvența se
     * poate deschide și publica în BPI într-o săptămână, deci o verificare > 7z e neacceptabilă pentru
     * depunere. Re-verifică pe bpi.just.ro.
     */
    private const INSOLVENCY_STALE_DAYS = 7;

    /**
     * @return list<AdmissibilityIssue>
     */
    public function validate(LegalCase $case, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $issues = [];

        foreach ($case->getDebtors() as $debtor) {
            foreach ($this->validateDebtor($debtor, $now) as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * @return list<AdmissibilityIssue>
     */
    private function validateDebtor(Debtor $debtor, \DateTimeImmutable $now): array
    {
        if ($debtor->getPersonType() !== PersonType::PJ) {
            // PF: BIPF (Legea 151/2015) nu are API; emitem WARNING static ca memento pentru avocat.
            return [new AdmissibilityIssue(
                IssueSeverity::WARNING,
                'OP_PF_BIPF_MANUAL_CHECK',
                'validation.op_admissibility.OP_PF_BIPF_MANUAL_CHECK',
            )];
        }

        if ($debtor->getAnafStatus() === AnafStatus::RADIAT) {
            return [new AdmissibilityIssue(
                IssueSeverity::ERROR,
                'OP_BLOCKED_DEREGISTERED',
                'validation.op_admissibility.OP_BLOCKED_DEREGISTERED',
            )];
        }

        if ($debtor->isInInsolvency()) {
            return [new AdmissibilityIssue(
                IssueSeverity::ERROR,
                'OP_BLOCKED_INSOLVENCY',
                'validation.op_admissibility.OP_BLOCKED_INSOLVENCY',
            )];
        }

        $issues = [];

        if ($debtor->getAnafStatus() === AnafStatus::INACTIV) {
            $issues[] = new AdmissibilityIssue(
                IssueSeverity::WARNING,
                'OP_DEFENDANT_FISCALLY_INACTIVE',
                'validation.op_admissibility.OP_DEFENDANT_FISCALLY_INACTIVE',
            );
        }

        if ($debtor->getAnafStatus() === null) {
            $issues[] = new AdmissibilityIssue(
                IssueSeverity::WARNING,
                'OP_ANAF_NOT_VERIFIED',
                'validation.op_admissibility.OP_ANAF_NOT_VERIFIED',
            );
        } elseif ($debtor->getAnafCheckedAt() === null
            || $this->isOlderThanDays($debtor->getAnafCheckedAt(), self::ANAF_STALE_DAYS, $now)) {
            // anafStatus setat fără anafCheckedAt = stare inconsistentă; o tratăm ca verificare stale
            // (nu avem invariant DB care să le lege; evităm să raportăm "OK" pentru date incomplete).
            $issues[] = new AdmissibilityIssue(
                IssueSeverity::WARNING,
                'OP_ANAF_STALE',
                'validation.op_admissibility.OP_ANAF_STALE',
            );
        }

        $bpiCheckedAt = $debtor->getInsolvencyCheckedAt();
        if ($bpiCheckedAt === null) {
            $issues[] = new AdmissibilityIssue(
                IssueSeverity::ERROR,
                'OP_INSOLVENCY_NOT_VERIFIED',
                'validation.op_admissibility.OP_INSOLVENCY_NOT_VERIFIED',
            );
        } elseif ($this->isOlderThanDays($bpiCheckedAt, self::INSOLVENCY_STALE_DAYS, $now)) {
            $issues[] = new AdmissibilityIssue(
                IssueSeverity::ERROR,
                'OP_INSOLVENCY_STALE',
                'validation.op_admissibility.OP_INSOLVENCY_STALE',
            );
        }

        return $issues;
    }

    private function isOlderThanDays(?\DateTimeImmutable $date, int $days, \DateTimeImmutable $now): bool
    {
        if ($date === null) {
            return false;
        }

        return $date < $now->modify(sprintf('-%d days', $days));
    }
}
