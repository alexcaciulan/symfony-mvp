<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\Enum\PersonType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Pas 3.3 — Step2DebtorsLiveComponent uses `LiveCollectionTrait` from the
 * bundle, which ships ready-made `addCollectionItem` / `removeCollectionItem`
 * LiveActions wired against the form's collection field path
 * (`step2_debtors[debtors]`). The Pas 3.2 non-JS `_action=add_debtor`
 * round-trip is gone; we test the LiveActions end-to-end through the bundle's
 * HTTP test harness — the controller hydrates the component, runs the action
 * against `formValues`, and re-renders. We assert on the rendered HTML count
 * of the per-debtor Stimulus controller markers.
 *
 * WebTestCase (not KernelTestCase) so the underlying KernelBrowser request
 * carries a session — Step2DebtorsType has `csrf_protection: true` and the
 * CSRF extension needs a session to mint tokens.
 */
final class Step2DebtorsLiveComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    protected function setUp(): void
    {
        // Boot KernelBrowser so the LiveComponent test client has session
        // middleware initialized.
        static::createClient();

        // Step2DebtorsType uses session-based CSRF (token_id `wizard_step2_debtors`
        // is NOT in framework.csrf_protection.stateless_token_ids — only `submit`
        // / `authenticate` / `logout` are stateless). The bundle's component
        // factory instantiates the form OUTSIDE an HTTP request, so the
        // RequestStack is empty and the session token storage panics. Push
        // a synthetic Request with an in-memory session so token generation
        // can read/write to it without hitting disk.
        $stack = static::getContainer()->get(RequestStack::class);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack->push($request);
    }

    public function testAddCollectionItemAppendsEmptyEntry(): void
    {
        $initial = new Step2DebtorsData([$this->makeEntry('First Debtor')]);
        $testComponent = $this->createLiveComponent(
            'Step2DebtorsLiveComponent',
            ['initialFormData' => $initial],
        );

        $beforeHtml = $testComponent->render()->toString();
        $this->assertDebtorCardCount(1, $beforeHtml);

        $testComponent->call('addCollectionItem', ['name' => 'step2_debtors[debtors]']);

        $afterHtml = $testComponent->render()->toString();
        $this->assertDebtorCardCount(2, $afterHtml);
    }

    public function testAddCollectionItemPreservesExistingEntries(): void
    {
        $initial = new Step2DebtorsData([
            $this->makeEntry('Alpha'),
            $this->makeEntry('Beta'),
        ]);
        $testComponent = $this->createLiveComponent(
            'Step2DebtorsLiveComponent',
            ['initialFormData' => $initial],
        );

        $testComponent->call('addCollectionItem', ['name' => 'step2_debtors[debtors]']);

        $afterHtml = $testComponent->render()->toString();
        $this->assertDebtorCardCount(3, $afterHtml);
        // Existing entries' names should survive the mutation.
        self::assertStringContainsString('Alpha', $afterHtml);
        self::assertStringContainsString('Beta', $afterHtml);
    }

    public function testRemoveCollectionItemDropsTheRightEntry(): void
    {
        $initial = new Step2DebtorsData([
            $this->makeEntry('Alpha'),
            $this->makeEntry('Beta'),
            $this->makeEntry('Gamma'),
        ]);
        $testComponent = $this->createLiveComponent(
            'Step2DebtorsLiveComponent',
            ['initialFormData' => $initial],
        );

        $testComponent->call('removeCollectionItem', [
            'name' => 'step2_debtors[debtors]',
            'index' => 1,
        ]);

        $afterHtml = $testComponent->render()->toString();
        $this->assertDebtorCardCount(2, $afterHtml);
        self::assertStringContainsString('Alpha', $afterHtml);
        self::assertStringContainsString('Gamma', $afterHtml);
        self::assertStringNotContainsString('Beta', $afterHtml);
    }

    public function testInitialRenderUsesInitialFormData(): void
    {
        $initial = new Step2DebtorsData([
            $this->makeEntry('Alpha'),
            $this->makeEntry('Beta'),
        ]);
        $testComponent = $this->createLiveComponent(
            'Step2DebtorsLiveComponent',
            ['initialFormData' => $initial],
        );

        $html = $testComponent->render()->toString();
        $this->assertDebtorCardCount(2, $html);
        self::assertStringContainsString('Alpha', $html);
        self::assertStringContainsString('Beta', $html);
    }

    public function testAddButtonIsDisabledAtCapInRenderedHtml(): void
    {
        $initial = new Step2DebtorsData(array_map(
            fn (int $i) => $this->makeEntry('Debtor ' . $i),
            range(1, 5),
        ));
        $testComponent = $this->createLiveComponent(
            'Step2DebtorsLiveComponent',
            ['initialFormData' => $initial],
        );

        $html = $testComponent->render()->toString();
        $this->assertDebtorCardCount(5, $html);
        // The add button must carry `disabled` / `aria-disabled="true"` when
        // the cap is reached so non-LiveAction click handlers (and a11y
        // tooling) can read the state.
        self::assertStringContainsString('aria-disabled="true"', $html);
    }

    private function assertDebtorCardCount(int $expected, string $html): void
    {
        // Match both attribute styles `data-controller="..."` and `data-controller='...'`
        // — the form widget rendering can vary.
        $count = substr_count($html, '"party-anaf-lookup"')
            + substr_count($html, "'party-anaf-lookup'");
        self::assertSame($expected, $count, "Expected {$expected} debtor cards in rendered HTML");
    }

    private function makeEntry(string $name): Step2DebtorEntry
    {
        return new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: $name,
            address: 'Str. Test nr. 1, București',
        );
    }
}
