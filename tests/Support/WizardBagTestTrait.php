<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Wizard\WizardBag;
use App\Service\Wizard\WizardStateStore;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Reads and writes the wizard state of the test client through the store the
 * container wires, so a test never depends on where the state lives.
 *
 * Expects the using class to hold the browser in `$this->client`.
 */
trait WizardBagTestTrait
{
    /**
     * Replaces the whole wizard state with the given array, normalized the way
     * the store normalizes it. Establishes a session with a first request when
     * the client has not made one yet.
     *
     * @param array<string, mixed> $raw
     */
    protected function primeBag(array $raw): void
    {
        $this->ensureWizardSession();
        $session = $this->wizardSession();
        $this->wizardStore()->save($session, WizardBag::fromArray($raw));
        $session->save();
    }

    /**
     * The wizard state as the store returns it, in the stored array shape.
     *
     * @return array<string, mixed>
     */
    protected function readBag(): array
    {
        return $this->wizardStore()->load($this->wizardSession())->toArray();
    }

    /**
     * Loads the state, lets the callable change it and saves it back.
     *
     * @param callable(WizardBag): void $mutate
     */
    protected function mutateBag(callable $mutate): void
    {
        $session = $this->wizardSession();
        $store = $this->wizardStore();
        $bag = $store->load($session);
        $mutate($bag);
        $store->save($session, $bag);
        $session->save();
    }

    private function ensureWizardSession(): void
    {
        try {
            $this->client->getRequest();
        } catch (\BadMethodCallException) {
            $this->client->request('GET', '/case/new/documents');
        }
    }

    private function wizardSession(): SessionInterface
    {
        return $this->client->getRequest()->getSession();
    }

    private function wizardStore(): WizardStateStore
    {
        return static::getContainer()->get(WizardStateStore::class);
    }
}
