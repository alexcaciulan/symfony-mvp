<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\Entity\CaseDraft;
use App\Entity\User;
use App\Repository\CaseDraftRepository;
use App\Repository\DocumentRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Keeps the wizard state in a CaseDraft row of the signed-in user; the
 * session only remembers which draft the browser is working on, and the
 * version of it the request loaded.
 *
 * Writes happen only when the bag changed, so the read-only requests of
 * step 0 (the polling frame, the page itself) never touch the row. A write
 * is guarded by the draft's version: when another request wrote in between,
 * the document list of both writes is kept and the rest follows the later
 * write, as it did when the session held the state.
 *
 * A draft is created on the first save of a non-empty bag and deleted when
 * the bag becomes empty again, so the list of unfinished cases never shows a
 * run with nothing in it. A session pointing at a draft that no longer
 * exists, or that cannot be read any more, starts over from an empty bag
 * and leaves the unreadable row for the list to show and the user to drop.
 */
final class CaseDraftWizardStateStore implements WizardStateStore
{
    public const SESSION_KEY = 'case_wizard_draft_id';
    public const VERSION_KEY = 'case_wizard_draft_version';

    /** How many times a conflicting write is merged and retried before giving up. */
    private const WRITE_ATTEMPTS = 3;

    public function __construct(
        private readonly CaseDraftRepository $drafts,
        private readonly DocumentRepository $documents,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CaseDraftPayloadCodec $codec,
        private readonly AuditLogService $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    public function load(SessionInterface $session): WizardBag
    {
        $draft = $this->currentDraft($session);
        if ($draft === null) {
            return new WizardBag();
        }

        $raw = $this->codec->decode($draft->getPayload(), $draft->getPayloadVersion());
        if ($raw === null) {
            $this->forget($session);

            return new WizardBag();
        }
        $session->set(self::VERSION_KEY, $draft->getVersion());

        $bag = WizardBag::fromArray($raw);
        // A document linked to this draft but missing from its list was lost to
        // a write the list did not see; the link column is what brings it back.
        $missing = $this->documents->findIdsByDraftNotIn($draft, $bag->documentIds);
        if ($missing !== []) {
            $bag->documentIds = [...$bag->documentIds, ...$missing];
        }

        return $bag;
    }

    public function save(SessionInterface $session, WizardBag $bag): void
    {
        $user = $this->requireUser();
        $draft = $this->currentDraft($session);
        $payload = $this->codec->encode($bag);
        $now = $this->clock->now();

        if ($draft === null) {
            if ($payload === $this->codec->emptyPayload()) {
                return;
            }
            $this->create($session, $user, $bag, $payload, $now);

            return;
        }

        if ($payload === $this->codec->emptyPayload()) {
            $this->auditLog->log(
                action: 'draft_discarded',
                entityType: CaseDraft::class,
                entityId: (string) $draft->getId(),
                oldData: self::describe($draft) + ['reason' => 'emptied'],
                category: AuditLogService::CATEGORY_CASE_DRAFT,
            );
            $this->drafts->removeDraft($draft);
            $this->forget($session);

            return;
        }

        if ($draft->getPayload() === $payload) {
            return;
        }

        $expected = $session->get(self::VERSION_KEY);
        $expected = is_int($expected) ? $expected : $draft->getVersion();
        for ($attempt = 1; $attempt <= self::WRITE_ATTEMPTS; ++$attempt) {
            $draft->denormalizeFrom($bag, $now);
            if ($this->drafts->updateIfVersion($draft, $expected, $payload, CaseDraftPayloadCodec::PAYLOAD_VERSION, $now)) {
                $session->set(self::VERSION_KEY, $draft->getVersion());
                $this->linkDocuments($draft, $bag->documentIds, $user);

                return;
            }

            // Another request wrote first. Its document list is kept together
            // with ours; for everything else the later write wins.
            try {
                $this->em->refresh($draft);
            } catch (EntityNotFoundException) {
                // Dropped from another tab in the meantime: this run starts a
                // draft of its own rather than writing into a deleted row.
                $this->forget($session);
                $this->create($session, $user, $bag, $payload, $now);

                return;
            }
            $theirs = $this->codec->decode($draft->getPayload(), $draft->getPayloadVersion());
            $theirIds = $theirs !== null ? WizardBag::fromArray($theirs)->documentIds : [];
            $bag->documentIds = array_values(array_unique([...$bag->documentIds, ...$theirIds]));
            $payload = $this->codec->encode($bag);
            $expected = $draft->getVersion();
        }

        throw new \RuntimeException(sprintf('Wizard draft %d could not be saved after %d concurrent writes', $draft->getId(), self::WRITE_ATTEMPTS));
    }

    public function reset(SessionInterface $session): void
    {
        $draft = $this->currentDraft($session);
        if ($draft !== null) {
            $this->drafts->removeDraft($draft);
        }
        $this->forget($session);
    }

    public function removeStored(SessionInterface $session): void
    {
        $draft = $this->currentDraft($session);
        if ($draft !== null) {
            $this->drafts->removeDraft($draft);
        }
    }

    public function detach(SessionInterface $session): void
    {
        $this->forget($session);
    }

    /** Makes the browser work on the given draft from now on. */
    public function attach(SessionInterface $session, CaseDraft $draft): void
    {
        $session->set(self::SESSION_KEY, $draft->getId());
        $session->set(self::VERSION_KEY, $draft->getVersion());
    }

    /** The draft the browser works on, if the session names one that still exists for this user. */
    public function currentDraft(SessionInterface $session): ?CaseDraft
    {
        $id = $session->get(self::SESSION_KEY);
        if (!is_int($id) || $id < 1) {
            return null;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }
        $draft = $this->drafts->findOneByIdAndUser($id, $user);
        if ($draft === null) {
            $this->forget($session);
        }

        return $draft;
    }

    /**
     * @return array{stepReached: string, documentsCount: int, creditorName: ?string}
     */
    public static function describe(CaseDraft $draft): array
    {
        return [
            'stepReached' => $draft->getStepReached(),
            'documentsCount' => $draft->getDocumentsCount(),
            'creditorName' => $draft->getCreditorName(),
        ];
    }

    private function create(SessionInterface $session, User $user, WizardBag $bag, string $payload, \DateTimeImmutable $now): void
    {
        $draft = new CaseDraft($user, $now);
        $draft->setPayload($payload, CaseDraftPayloadCodec::PAYLOAD_VERSION)->denormalizeFrom($bag, $now);
        $this->em->persist($draft);
        $this->em->flush();

        $this->auditLog->log(
            action: 'draft_created',
            entityType: CaseDraft::class,
            entityId: (string) $draft->getId(),
            newData: self::describe($draft),
            category: AuditLogService::CATEGORY_CASE_DRAFT,
        );
        $this->em->flush();

        $this->attach($session, $draft);
        $this->linkDocuments($draft, $bag->documentIds, $user);
    }

    /**
     * Points the user's own pending documents named by the bag at the draft.
     *
     * @param list<int> $documentIds
     */
    private function linkDocuments(CaseDraft $draft, array $documentIds, User $user): void
    {
        if ($documentIds === []) {
            return;
        }
        $changed = false;
        foreach ($this->documents->findBy(['id' => $documentIds]) as $document) {
            if ($document->getUploadedBy()->getId() !== $user->getId() || $document->getLegalCase() !== null) {
                continue;
            }
            if ($document->getDraft()?->getId() === $draft->getId()) {
                continue;
            }
            $document->setDraft($draft);
            $changed = true;
        }
        if ($changed) {
            $this->em->flush();
        }
    }

    private function forget(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
        $session->remove(self::VERSION_KEY);
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The wizard draft store needs a signed-in user.');
        }

        return $user;
    }
}
