<?php

namespace App\Enum;

enum CaseTransition: string
{
    case TRIMITE_SOMATIE = 'trimite_somatie';
    case GENEREAZA_CERERE = 'genereaza_cerere';
    case DEPUNE_CERERE = 'depune_cerere';
    case INREGISTREAZA_DOSAR = 'inregistreaza_dosar';
    case FIXEAZA_TERMEN = 'fixeaza_termen';
    case EMITE_ORDONANTA = 'emite_ordonanta';
    case FORMULEAZA_CERERE_ANULARE = 'formuleaza_cerere_anulare';
    case RESPINGE_CERERE_ANULARE = 'respinge_cerere_anulare';
    case RESPINGE_CERERE_ANULARE_EXECUTARE = 'respinge_cerere_anulare_executare';
    case ADMITE_CERERE_ANULARE = 'admite_cerere_anulare';
    case MARCHEAZA_DEFINITIVA = 'marcheaza_definitiva';
    case RESPINGE = 'respinge';
    case TRECE_LA_EXECUTARE = 'trece_la_executare';
    case INCHIDE_SUCCES = 'inchide_succes';
    case INCHIDE_FARA_RECUPERARE = 'inchide_fara_recuperare';

    public function label(): string
    {
        return 'enum.case_transition.' . $this->value;
    }
}
