<?php

namespace App\Enum;

enum CaseTransition: string
{
    case SUBMIT = 'submit';
    case CONFIRM_PAYMENT = 'confirm_payment';
    case SUBMIT_TO_COURT = 'submit_to_court';
    case MARK_RECEIVED = 'mark_received';
    case REQUEST_INFO = 'request_info';
    case PROVIDE_INFO = 'provide_info';
    case ACCEPT = 'accept';
    case REJECT = 'reject';
    case ENFORCE = 'enforce';

    public function label(): string
    {
        return match ($this) {
            self::SUBMIT => 'Depune (draft → așteptare plată)',
            self::CONFIRM_PAYMENT => 'Confirmă plata',
            self::SUBMIT_TO_COURT => 'Trimite la instanță',
            self::MARK_RECEIVED => 'Marchează recepționat',
            self::REQUEST_INFO => 'Solicită informații',
            self::PROVIDE_INFO => 'Informații furnizate',
            self::ACCEPT => 'Admite',
            self::REJECT => 'Respinge',
            self::ENFORCE => 'Executare silită',
        };
    }
}
