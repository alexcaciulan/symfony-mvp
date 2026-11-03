<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Keeps the wizard state in the HTTP session, as the plain array the bag
 * serializes to, under a single key.
 *
 * A session holds one run at a time, so detaching from the run and dropping
 * it are the same thing here.
 */
final class SessionWizardStateStore implements WizardStateStore
{
    public const SESSION_KEY = 'case_wizard_data';

    public function load(SessionInterface $session): WizardBag
    {
        $raw = $session->get(self::SESSION_KEY, []);

        return WizardBag::fromArray(is_array($raw) ? $raw : []);
    }

    public function save(SessionInterface $session, WizardBag $bag): void
    {
        $session->set(self::SESSION_KEY, $bag->toArray());
    }

    public function reset(SessionInterface $session): void
    {
        $session->set(self::SESSION_KEY, (new WizardBag())->toArray());
    }

    public function removeStored(SessionInterface $session): void {}

    public function detach(SessionInterface $session): void
    {
        $this->reset($session);
    }
}
