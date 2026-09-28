<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What the contract calls its late-payment accessory, so the payment notice
 * names it in the contract's own words. Only meaningful for
 * {@see PenaltyType::CONTRACTUAL}; the statutory branch always says
 * "dobândă legală penalizatoare".
 *
 * Every phrase is a feminine plural, so the fixed text around it ("calculate",
 * "care vor curge") agrees with whichever one is chosen.
 *
 * Values are persisted: never rename one, an orphaned value breaks hydration.
 */
enum ContractualAccessoryLabel: string
{
    case PENALITATI_INTARZIERE = 'PENALITATI_INTARZIERE';
    case DOBANZI_PENALIZATOARE = 'DOBANZI_PENALIZATOARE';
    case MAJORARI_INTARZIERE = 'MAJORARI_INTARZIERE';

    public const DEFAULT = self::PENALITATI_INTARZIERE;

    /** Choice label in the wizard. */
    public function label(): string
    {
        return 'enum.contractual_accessory_label.' . $this->value;
    }

    /** The phrase as it reads inside the payment notice. */
    public function phrase(): string
    {
        return 'pdf.summons.accessory_label.' . $this->value;
    }
}
