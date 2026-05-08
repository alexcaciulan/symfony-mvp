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
        return match ($this) {
            self::TRIMITE_SOMATIE => 'Trimite somație',
            self::DEPUNE_CERERE => 'Depune cerere ordonanță de plată',
            self::INREGISTREAZA_DOSAR => 'Înregistrează dosar instanță',
            self::FIXEAZA_TERMEN => 'Fixează termen de judecată',
            self::EMITE_ORDONANTA => 'Emite ordonanță',
            self::CONTESTA => 'Contestă',
            self::RESPINGE_CONTESTATIE => 'Respinge contestația',
            self::ADMITE_CONTESTATIE => 'Admite contestația',
            self::MARCHEAZA_DEFINITIVA => 'Marchează definitivă',
            self::RESPINGE => 'Respinge cererea',
            self::INCHIDE_SUCCES => 'Închide cu succes',
            self::INCHIDE_INSOLVABIL => 'Închide ca insolvabil',
        };
    }
}
