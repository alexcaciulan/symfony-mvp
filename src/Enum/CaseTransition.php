<?php

namespace App\Enum;

enum CaseTransition: string
{
    case TRIMITE_SOMATIE = 'trimite_somatie';
    case DEPUNE_CERERE = 'depune_cerere';
    case INREGISTREAZA_DOSAR = 'inregistreaza_dosar';
    case FIXEAZA_TERMEN = 'fixeaza_termen';
    case EMITE_ORDONANTA = 'emite_ordonanta';
    case CONTESTA = 'contesta';
    case RESPINGE_CONTESTATIE = 'respinge_contestatie';
    case ADMITE_CONTESTATIE = 'admite_contestatie';
    case MARCHEAZA_DEFINITIVA = 'marcheaza_definitiva';
    case RESPINGE = 'respinge';
    case INCHIDE_SUCCES = 'inchide_succes';
    case INCHIDE_INSOLVABIL = 'inchide_insolvabil';

    public function label(): string
    {
        return 'enum.case_transition.' . $this->value;
    }
}
