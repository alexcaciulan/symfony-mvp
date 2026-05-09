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
    case BPI_PROOF = 'bpi_proof';
    case ALT_DOCUMENT = 'alt_document';

    public function label(): string
    {
        return 'enum.document_type.' . $this->value;
    }
}
