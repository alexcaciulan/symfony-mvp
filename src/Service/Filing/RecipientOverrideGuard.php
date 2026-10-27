<?php

declare(strict_types=1);

namespace App\Service\Filing;

/**
 * Decides where a filing really goes. Outside live mode every filing goes to the
 * override address and nothing may reach a court domain, whatever APP_ENV says:
 * the demo runs as prod with real court addresses imported. In live mode the
 * override must be empty, so a forgotten test setting never diverts a real filing.
 */
final class RecipientOverrideGuard
{
    private const COURT_DOMAIN = 'just.ro';

    public function __construct(
        private readonly bool $courtFilingLive,
        private readonly string $courtFilingRecipientOverride,
    ) {}

    public function isLive(): bool
    {
        return $this->courtFilingLive;
    }

    public function override(): ?string
    {
        $override = trim($this->courtFilingRecipientOverride);

        return $override === '' ? null : mb_strtolower($override);
    }

    /** @throws CourtFilingNotAllowedException */
    public function assertConfiguration(): void
    {
        $override = $this->override();
        if ($this->courtFilingLive) {
            if ($override !== null) {
                throw new CourtFilingNotAllowedException('COURT_FILING_LIVE=1 requires COURT_FILING_RECIPIENT_OVERRIDE to be empty.');
            }

            return;
        }

        if ($override === null) {
            throw new CourtFilingNotAllowedException('Without COURT_FILING_LIVE=1, COURT_FILING_RECIPIENT_OVERRIDE is required.');
        }
        if (!filter_var($override, FILTER_VALIDATE_EMAIL)) {
            throw new CourtFilingNotAllowedException('COURT_FILING_RECIPIENT_OVERRIDE is not a valid email address.');
        }
        if (self::isCourtDomain($override)) {
            throw new CourtFilingNotAllowedException('COURT_FILING_RECIPIENT_OVERRIDE must not be a court address.');
        }
    }

    /**
     * The effective recipient for a filing meant for $intendedTo.
     *
     * @throws CourtFilingNotAllowedException
     */
    public function resolve(string $intendedTo, string $cc): string
    {
        $this->assertConfiguration();
        if ($this->courtFilingLive) {
            return $intendedTo;
        }

        // The lawyer's copy is a real recipient too; outside live mode it must not be a court.
        if (self::isCourtDomain($cc)) {
            throw new CourtFilingNotAllowedException('Outside live mode the copy recipient must not be a court address.');
        }

        return (string) $this->override();
    }

    private static function isCourtDomain(string $address): bool
    {
        $domain = mb_strtolower(trim((string) substr((string) strrchr($address, '@'), 1)));

        return $domain === self::COURT_DOMAIN || str_ends_with($domain, '.' . self::COURT_DOMAIN);
    }
}
