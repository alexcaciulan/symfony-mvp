<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\ClaimItemKind;
use PHPUnit\Framework\TestCase;

class ClaimItemKindTest extends TestCase
{
    public function testValuesAreStableSnakeCase(): void
    {
        $this->assertSame(
            ['invoice', 'contract_instalment', 'credit_note', 'other'],
            array_map(static fn (ClaimItemKind $k): string => $k->value, ClaimItemKind::cases()),
        );
    }

    public function testLabelPointsAtTheEnumCatalogue(): void
    {
        $this->assertSame('enum.claim_item_kind.invoice', ClaimItemKind::INVOICE->label());
        $this->assertSame('enum.claim_item_kind.other', ClaimItemKind::OTHER->label());
    }
}
