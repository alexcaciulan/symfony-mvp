<?php

namespace App\Enum;

enum LegalGroundCategory: string
{
    case CONTRACT_VANZARE = 'CONTRACT_VANZARE';
    case CONTRACT_PRESTARI_SERVICII = 'CONTRACT_PRESTARI_SERVICII';
    case CONTRACT_LOCATIUNE = 'CONTRACT_LOCATIUNE';
    case CONTRACT_IMPRUMUT = 'CONTRACT_IMPRUMUT';
    case FACTURA_ACCEPTATA = 'FACTURA_ACCEPTATA';
    case BILET_LA_ORDIN = 'BILET_LA_ORDIN';
    case CEC = 'CEC';
    case CAMBIE = 'CAMBIE';
    case ALTE_INSCRISURI = 'ALTE_INSCRISURI';

    public function label(): string
    {
        return 'enum.legal_ground_category.' . $this->value;
    }

    /**
     * Whether this legal ground is admissible under the payment-order procedure
     * (CPC art. 1013: creanță certă, lichidă și exigibilă, constatată prin înscris).
     *
     * All current categories are eligible; the helper is a future-proof guard
     * for cases that may need exclusion at a later revision.
     */
    public function isOpEligible(): bool
    {
        return match ($this) {
            self::CONTRACT_VANZARE,
            self::CONTRACT_PRESTARI_SERVICII,
            self::CONTRACT_LOCATIUNE,
            self::CONTRACT_IMPRUMUT,
            self::FACTURA_ACCEPTATA,
            self::BILET_LA_ORDIN,
            self::CEC,
            self::CAMBIE,
            self::ALTE_INSCRISURI => true,
        };
    }

    /**
     * Whether this category is already a directly enforceable instrument
     * (titlu executoriu de plin drept), in which case direct enforcement
     * (executare silită) is usually faster and cheaper than payment-order.
     *
     * - CEC: titlu executoriu per Legea 59/1934 art. 53.
     * - CAMBIE: titlu executoriu per Legea 58/1934 art. 61.
     * - BILET_LA_ORDIN: titlu executoriu per Legea 58/1934 art. 106 (referința art. 61).
     *
     * The UI should warn the lawyer that, although payment-order remains
     * procedurally available (`isOpEligible() === true`), direct enforcement
     * is typically the optimal choice. Consumed by the wizard step claim
     * (Pas 3.1) and by the extraction pipeline UI (Pas 3.0).
     */
    public function isDirectlyEnforceable(): bool
    {
        return match ($this) {
            self::CEC,
            self::CAMBIE,
            self::BILET_LA_ORDIN => true,
            self::CONTRACT_VANZARE,
            self::CONTRACT_PRESTARI_SERVICII,
            self::CONTRACT_LOCATIUNE,
            self::CONTRACT_IMPRUMUT,
            self::FACTURA_ACCEPTATA,
            self::ALTE_INSCRISURI => false,
        };
    }
}
