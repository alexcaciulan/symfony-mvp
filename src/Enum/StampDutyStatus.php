<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Payment state of the judicial stamp duty (OUG 80/2013). The duty is paid by the
 * claimant into the local budget account of their own registered-office UAT, never
 * through this platform, so the state here tracks evidence of payment, not a payment.
 */
enum StampDutyStatus: string
{
    case NEACHITATA = 'NEACHITATA';
    case ACHITATA = 'ACHITATA';

    /**
     * The lawyer knowingly filed without proof and will stamp during the court's
     * regularization procedure (OUG 80/2013 art. 33 alin. 2: 10 days from the
     * court's notice, under penalty of annulment).
     */
    case AMANATA_REGULARIZARE = 'AMANATA_REGULARIZARE';

    /** Reserved for the refund flow (OUG 80/2013 art. 45). Not reachable yet. */
    case RESTITUITA = 'RESTITUITA';

    public function label(): string
    {
        return 'enum.stamp_duty_status.' . $this->value;
    }

    public function color(): string
    {
        return match ($this) {
            self::NEACHITATA => 'amber',
            self::ACHITATA => 'green',
            self::AMANATA_REGULARIZARE => 'orange',
            self::RESTITUITA => 'slate',
        };
    }

    /** Whether the case may proceed to the court-filing package. */
    public function allowsFiling(): bool
    {
        return match ($this) {
            self::ACHITATA, self::AMANATA_REGULARIZARE => true,
            self::NEACHITATA, self::RESTITUITA => false,
        };
    }
}
