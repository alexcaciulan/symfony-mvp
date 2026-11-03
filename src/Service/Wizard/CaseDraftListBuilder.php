<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\Entity\CaseDraft;
use App\Entity\User;
use App\Repository\CaseDraftRepository;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The unfinished runs of a user as the lists show them: which one the
 * browser is working on, and which can no longer be read and can only be
 * dropped.
 */
final class CaseDraftListBuilder
{
    public function __construct(
        private readonly CaseDraftRepository $drafts,
        private readonly CaseDraftPayloadCodec $codec,
        private readonly CaseDraftWizardStateStore $store,
    ) {}

    /**
     * @return list<array{draft: CaseDraft, active: bool, unreadable: bool}>
     */
    public function forUser(User $user, SessionInterface $session): array
    {
        $activeId = $this->store->currentDraft($session)?->getId();
        $rows = [];
        foreach ($this->drafts->findByUser($user) as $draft) {
            $rows[] = [
                'draft' => $draft,
                'active' => $activeId !== null && $draft->getId() === $activeId,
                'unreadable' => !$this->codec->isReadable($draft),
            ];
        }

        return $rows;
    }
}
