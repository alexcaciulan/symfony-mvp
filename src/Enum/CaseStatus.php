<?php

namespace App\Enum;

enum CaseStatus: string
{
    case AMIABIL = 'AMIABIL';
    case SOMATIE_TRIMISA = 'SOMATIE_TRIMISA';
    case CERERE_DEPUSA = 'CERERE_DEPUSA';
    case DOSAR_INREGISTRAT = 'DOSAR_INREGISTRAT';
    case TERMEN_FIXAT = 'TERMEN_FIXAT';
    case ORDONANTA_EMISA = 'ORDONANTA_EMISA';
    case CONTESTATA = 'CONTESTATA';
    case DEFINITIVA = 'DEFINITIVA';
    case EXECUTARE = 'EXECUTARE';
    case RESPINSA = 'RESPINSA';
    case INCHIS_SUCCES = 'INCHIS_SUCCES';
    case INCHIS_PARTIAL_INSOLVABIL = 'INCHIS_PARTIAL_INSOLVABIL';

    public function label(): string
    {
        return 'enum.case_status.' . $this->value;
    }

    public function color(): string
    {
        return match ($this) {
            self::AMIABIL => 'slate',
            self::SOMATIE_TRIMISA => 'amber',
            self::CERERE_DEPUSA => 'sky',
            self::DOSAR_INREGISTRAT => 'blue',
            self::TERMEN_FIXAT => 'indigo',
            self::ORDONANTA_EMISA => 'violet',
            self::CONTESTATA => 'orange',
            self::DEFINITIVA => 'emerald',
            self::EXECUTARE => 'teal',
            self::RESPINSA => 'red',
            self::INCHIS_SUCCES => 'green',
            self::INCHIS_PARTIAL_INSOLVABIL => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::RESPINSA, self::INCHIS_SUCCES, self::INCHIS_PARTIAL_INSOLVABIL => true,
            default => false,
        };
    }

    public function isActiveOnPortal(): bool
    {
        return match ($this) {
            self::DOSAR_INREGISTRAT, self::TERMEN_FIXAT, self::ORDONANTA_EMISA, self::CONTESTATA => true,
            default => false,
        };
    }
}
