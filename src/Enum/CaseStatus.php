<?php

declare(strict_types=1);

namespace App\Enum;

enum CaseStatus: string
{
    case AMIABIL = 'AMIABIL';

    /**
     * Somația de plată a fost generată (PDF) și avocatul urmează să o comunice
     * debitorului. Statusul NU înseamnă „comunicare confirmată" — el reflectă
     * intenția procedurală a creditorului. Termenul legal de 15 zile (CPC
     * art. 1015 alin. 1) curge de la PRIMIREA somației de către debitor, nu
     * de la data acestei tranziții. Pas 4.1 (DeadlineService) va calcula
     * termenul pe baza datei comunicării efective (atașată ca dovadă), nu
     * pe baza `paymentNoticeDate` setat la generare.
     */
    case SOMATIE_TRIMISA = 'SOMATIE_TRIMISA';

    /**
     * The petition package exists but nothing has left for the court yet. Filing
     * is the lawyer's own act, on a channel the platform does not operate, so it
     * cannot be inferred from the fact that a PDF was produced. Keeping the two
     * apart matters beyond bookkeeping: the six-month term of NCC art. 2540 runs
     * on the request reaching the court, not on generating it.
     */
    case CERERE_GENERATA = 'CERERE_GENERATA';

    /** Confirmed by the lawyer, with the date and channel they filed on. */
    case CERERE_DEPUSA = 'CERERE_DEPUSA';
    case DOSAR_INREGISTRAT = 'DOSAR_INREGISTRAT';
    case TERMEN_FIXAT = 'TERMEN_FIXAT';
    case ORDONANTA_EMISA = 'ORDONANTA_EMISA';
    case IN_ANULARE = 'IN_ANULARE';
    case DEFINITIVA = 'DEFINITIVA';
    case EXECUTARE = 'EXECUTARE';
    case RESPINSA = 'RESPINSA';
    case INCHIS_SUCCES = 'INCHIS_SUCCES';
    case INCHIS_FARA_RECUPERARE = 'INCHIS_FARA_RECUPERARE';

    public function label(): string
    {
        return 'enum.case_status.' . $this->value;
    }

    public function color(): string
    {
        return match ($this) {
            self::AMIABIL => 'slate',
            self::SOMATIE_TRIMISA => 'amber',
            self::CERERE_GENERATA => 'purple',
            self::CERERE_DEPUSA => 'sky',
            self::DOSAR_INREGISTRAT => 'blue',
            self::TERMEN_FIXAT => 'indigo',
            self::ORDONANTA_EMISA => 'violet',
            self::IN_ANULARE => 'orange',
            self::DEFINITIVA => 'emerald',
            self::EXECUTARE => 'teal',
            self::RESPINSA => 'red',
            self::INCHIS_SUCCES => 'green',
            self::INCHIS_FARA_RECUPERARE => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::RESPINSA, self::INCHIS_SUCCES, self::INCHIS_FARA_RECUPERARE => true,
            default => false,
        };
    }

    public function isActiveOnPortal(): bool
    {
        return match ($this) {
            self::DOSAR_INREGISTRAT, self::TERMEN_FIXAT, self::ORDONANTA_EMISA, self::IN_ANULARE => true,
            default => false,
        };
    }
}
