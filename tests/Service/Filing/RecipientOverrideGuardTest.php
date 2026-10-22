<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Service\Filing\CourtFilingNotAllowedException;
use App\Service\Filing\RecipientOverrideGuard;
use PHPUnit\Framework\TestCase;

final class RecipientOverrideGuardTest extends TestCase
{
    public function testOutsideLiveModeEveryFilingGoesToTheOverride(): void
    {
        $guard = new RecipientOverrideGuard(false, 'Simulare@Example.test');

        self::assertSame('simulare@example.test', $guard->resolve('registratura@just.ro', 'avocat@example.test'));
    }

    public function testOutsideLiveModeTheOverrideIsRequired(): void
    {
        $this->expectException(CourtFilingNotAllowedException::class);

        (new RecipientOverrideGuard(false, ''))->resolve('registratura@just.ro', 'avocat@example.test');
    }

    public function testOverrideOnACourtDomainIsRefused(): void
    {
        $this->expectException(CourtFilingNotAllowedException::class);

        (new RecipientOverrideGuard(false, 'grefa@jud-sector3.just.ro'))->resolve('registratura@just.ro', 'avocat@example.test');
    }

    public function testCopyOnACourtDomainIsRefusedOutsideLiveMode(): void
    {
        $this->expectException(CourtFilingNotAllowedException::class);

        (new RecipientOverrideGuard(false, 'simulare@example.test'))->resolve('registratura@just.ro', 'cineva@just.ro');
    }

    public function testLiveModeRefusesAForgottenOverride(): void
    {
        $this->expectException(CourtFilingNotAllowedException::class);

        (new RecipientOverrideGuard(true, 'simulare@example.test'))->resolve('registratura@just.ro', 'avocat@example.test');
    }

    public function testLiveModeWithoutOverrideSendsToTheCourt(): void
    {
        $guard = new RecipientOverrideGuard(true, '');

        self::assertSame('registratura@just.ro', $guard->resolve('registratura@just.ro', 'avocat@example.test'));
        self::assertTrue($guard->isLive());
        self::assertNull($guard->override());
    }
}
