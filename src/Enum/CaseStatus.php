<?php

namespace App\Enum;

enum CaseStatus: string
{
    case DRAFT = 'draft';
    case PENDING_PAYMENT = 'pending_payment';
    case PAID = 'paid';
    case SUBMITTED_TO_COURT = 'submitted_to_court';
    case UNDER_REVIEW = 'under_review';
    case ADDITIONAL_INFO_REQUESTED = 'additional_info_requested';
    case RESOLVED_ACCEPTED = 'resolved_accepted';
    case RESOLVED_REJECTED = 'resolved_rejected';
    case ENFORCEMENT = 'enforcement';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Ciornă',
            self::PENDING_PAYMENT => 'În așteptarea plății',
            self::PAID => 'Plătit',
            self::SUBMITTED_TO_COURT => 'Trimis la instanță',
            self::UNDER_REVIEW => 'În analiză',
            self::ADDITIONAL_INFO_REQUESTED => 'Info suplimentare',
            self::RESOLVED_ACCEPTED => 'Admis',
            self::RESOLVED_REJECTED => 'Respins',
            self::ENFORCEMENT => 'Executare silită',
        };
    }
}
