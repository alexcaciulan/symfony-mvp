<?php

declare(strict_types=1);

namespace App\Enum;

/** One physical file of a filing package. */
enum FilingPartRole: string
{
    /** The petition, with the index appended when the index is merged into it. */
    case PETITION = 'PETITION';
    /** The index as its own file, only when it is not merged into the petition. */
    case OPIS = 'OPIS';
    case ANNEX_BUNDLE = 'ANNEX_BUNDLE';
    case POWER_OF_ATTORNEY = 'POWER_OF_ATTORNEY';

    public function label(): string
    {
        return 'enum.filing_part_role.' . $this->value;
    }

    /** Stable ASCII stem for generated file names. */
    public function fileStem(): string
    {
        return match ($this) {
            self::PETITION => 'cerere',
            self::OPIS => 'opis',
            self::ANNEX_BUNDLE => 'anexe',
            self::POWER_OF_ATTORNEY => 'imputernicire',
        };
    }
}
