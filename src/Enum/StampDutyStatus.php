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
     * The lawyer will pay when filing, in the electronic registry form itself,
     * which takes the payment and the petition in one step. Anticipated payment
     * in the sense of OUG 80/2013 art. 33 alin. 1, not a deferral: it simply
     * happens after we build the package, because the package is what gets filed.
     *
     * Like AMANATA_REGULARIZARE this records an intention rather than an observed
     * payment. The enum carries both because what the petition may claim in front
     * of the court depends on the lawyer's plan, not only on money already moved.
     */
    case ACHITARE_LA_DEPUNERE = 'ACHITARE_LA_DEPUNERE';

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
            self::ACHITARE_LA_DEPUNERE => 'blue',
            self::AMANATA_REGULARIZARE => 'orange',
            self::RESTITUITA => 'slate',
        };
    }

    /** Whether the case may proceed to the court-filing package. */
    public function allowsFiling(): bool
    {
        return match ($this) {
            self::ACHITATA, self::ACHITARE_LA_DEPUNERE, self::AMANATA_REGULARIZARE => true,
            self::NEACHITATA, self::RESTITUITA => false,
        };
    }

    /**
     * Whether the duty is still owed at this point, so the follow-up has something
     * to chase. Both intentions qualify: money has not moved yet in either.
     */
    public function isOutstanding(): bool
    {
        return match ($this) {
            self::NEACHITATA, self::ACHITARE_LA_DEPUNERE, self::AMANATA_REGULARIZARE => true,
            self::ACHITATA, self::RESTITUITA => false,
        };
    }
}
