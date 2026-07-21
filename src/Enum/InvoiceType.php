<?php

declare(strict_types=1);

namespace App\Enum;

enum InvoiceType: string
{
    /** Recurring monthly subscription charge. */
    case SUBSCRIPTION = 'subscription';

    /** Per-case charge billed when the plan's included cases are exhausted. */
    case CASE_EXTRA = 'case_extra';

    /** Full price of the plan a subscription moves to once this invoice is settled. */
    case PLAN_CHANGE = 'plan_change';

    public function label(): string
    {
        return 'enum.invoice_type.' . $this->value;
    }
}
