<?php

namespace App\Enum;

enum DocumentType: string
{
    case SOMATIE = 'somatie';
    case CERERE_OP = 'cerere_op';
    case OPIS = 'opis';
    case DOVADA_COMUNICARE = 'dovada_comunicare';
    case ACT_CONSTATATOR = 'act_constatator';
    case ANEXA = 'anexa';
    case DOVADA = 'dovada';
    case CONTRACT = 'contract';
    case FACTURA = 'factura';
    case ALT_DOCUMENT = 'alt_document';

    public function label(): string
    {
        return match ($this) {
            self::SOMATIE => 'Somație',
            self::CERERE_OP => 'Cerere ordonanță de plată',
            self::OPIS => 'Opis documente',
            self::DOVADA_COMUNICARE => 'Dovadă comunicare',
            self::ACT_CONSTATATOR => 'Act constatator',
            self::ANEXA => 'Anexă',
            self::DOVADA => 'Dovadă',
            self::CONTRACT => 'Contract',
            self::FACTURA => 'Factură',
            self::ALT_DOCUMENT => 'Alt document',
        };
    }
}
