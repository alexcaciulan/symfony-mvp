<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where the package index goes. Merged into the petition it costs no extra
 * signature, but any change to the index then invalidates the petition's.
 */
enum OpisPlacement: string
{
    case MERGED = 'MERGED';
    case SEPARATE = 'SEPARATE';

    public function label(): string
    {
        return 'enum.opis_placement.' . $this->value;
    }
}
