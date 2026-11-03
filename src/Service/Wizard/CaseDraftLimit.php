<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\Entity\User;
use App\Repository\CaseDraftRepository;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * How many unfinished runs a user may keep at once. Past the cap a new run
 * is refused and the user is sent to the list to continue or drop one.
 */
final class CaseDraftLimit
{
    public const MAX_PER_USER = 5;

    public function __construct(
        private readonly CaseDraftRepository $drafts,
        private readonly CaseDraftWizardStateStore $store,
    ) {}

    public function isReached(User $user): bool
    {
        return $this->drafts->countByUser($user) >= self::MAX_PER_USER;
    }

    /** Whether starting a run in this session would create a draft past the cap. */
    public function blocksNewRun(User $user, SessionInterface $session): bool
    {
        return $this->store->currentDraft($session) === null && $this->isReached($user);
    }
}
