<?php

namespace App\Enum;

enum DocumentType: string
{
    case CERERE_PDF = 'cerere_pdf';
    case DOVADA = 'dovada';
    case CONTRACT = 'contract';
    case FACTURA = 'factura';
    case ALT_DOCUMENT = 'alt_document';

    public function label(): string
    {
        return match ($this) {
            self::CERERE_PDF => 'Cerere PDF',
            self::DOVADA => 'Dovadă',
            self::CONTRACT => 'Contract',
            self::FACTURA => 'Factură',
            self::ALT_DOCUMENT => 'Alt document',
        };
    }
}
