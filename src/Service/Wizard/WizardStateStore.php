<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Where the state of a wizard run lives between two requests.
 *
 * The controller never touches the storage itself: it loads a bag, works on
 * it and saves it back, so the same wizard code runs whatever keeps the state.
 */
interface WizardStateStore
{
    /** The state of the current run, normalized; an empty bag when there is none. */
    public function load(SessionInterface $session): WizardBag;

    public function save(SessionInterface $session, WizardBag $bag): void;

    /** Drops the current run so the next load starts from an empty bag. */
    public function reset(SessionInterface $session): void;
}
