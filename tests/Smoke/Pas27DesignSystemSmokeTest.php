<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pas 2.7 — design-system shell smoke checks.
 *
 * Asserts that the v2 redesign tokens / sidebar / topbar / split auth layout
 * actually reach the rendered HTML. Tests are intentionally shallow (HTML
 * substring assertions) — full visual parity is verified manually against
 * docs/LexRecovery/mockups/v2/ during review.
 */
final class Pas27DesignSystemSmokeTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testLoginUsesSplitLayoutWithBrandingAndDarkToggle(): void
    {
        $this->client->request('GET', '/login');
        $this->assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();

        // Branding side (lg+) is present.
        $this->assertStringContainsString('from-lex-navy', $body);
        // Floating dark-toggle exists on the split layout.
        $this->assertStringContainsString('data-controller="dark-mode-toggle"', $body);
        // Branding tagline is rendered.
        $this->assertStringContainsString('Recuperare creanțe', $body);
        // Auth body class sets the radial-gradient background.
        $this->assertStringContainsString('auth-bg', $body);
        // Inter font is loaded.
        $this->assertStringContainsString('family=Inter', $body);
    }

    public function testRegisterUsesSplitLayoutWithBranding(): void
    {
        $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();

        $this->assertStringContainsString('from-lex-navy', $body);
        $this->assertStringContainsString('data-controller="dark-mode-toggle"', $body);
        $this->assertStringContainsString('auth-bg', $body);
    }

    public function testAuthenticatedDashboardRendersSidebarTopbarAndKpis(): void
    {
        $userRepository = static::getContainer()->get(UserRepository::class);
        // A verified user: the dashboard is behind the email-verification gate.
        $user = $userRepository->findOneBy(['isVerified' => true]);

        if (!$user instanceof User) {
            $this->markTestSkipped('No verified user available. Run app:create-test-users.');
        }

        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();

        // Sidebar (fixed lg+, 256px) is rendered.
        $this->assertStringContainsString('w-64', $body);
        $this->assertStringContainsString('fixed inset-y-0 left-0', $body);
        // Sidebar nav item: Dashboard.
        $this->assertStringContainsString('Dashboard', $body);
        // Topbar uses lex-navy brand color for the CTA.
        $this->assertStringContainsString('bg-lex-navy', $body);
        // Inter font + Tailwind v4 + slate base palette.
        $this->assertStringContainsString('font-sans antialiased', $body);
        $this->assertStringContainsString('bg-slate-50', $body);
        // Dashboard renders either the empty-state hero OR the KPI grid (lg:grid-cols-4 marker).
        $this->assertTrue(
            str_contains($body, 'HeroEmptyState')
                || str_contains($body, 'shadow-soft')
                || str_contains($body, 'tnum'),
            'Dashboard should render either empty-state hero or KPI cards'
        );
    }
}
