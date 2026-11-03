<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CaseDraftRepository;
use App\Service\Wizard\WizardBag;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A wizard run that was not submitted yet, kept per user so the work survives
 * the session.
 *
 * `payload` is the serialized bag (see CaseDraftPayloadCodec); it carries
 * personal data and is never shown anywhere. The other columns are
 * denormalized from the bag for the list of unfinished cases and hold no
 * personal data: the creditor is a legal person.
 *
 * `version` is an optimistic lock applied by CaseDraftRepository::updateIfVersion
 * with a conditional UPDATE rather than by the ORM's own versioning: a lock
 * failure in the ORM closes the entity manager, and the store has to reload,
 * merge and retry within the same request.
 */
#[ORM\Entity(repositoryClass: CaseDraftRepository::class)]
#[ORM\Table(name: 'case_draft')]
#[ORM\Index(columns: ['last_activity_at'], name: 'idx_case_draft_last_activity')]
class CaseDraft
{
    public const STEP_DOCUMENTS = 'documents';
    public const STEP_CREDITOR = 'creditor';
    public const STEP_DEBTOR = 'debtor';
    public const STEP_CLAIM = 'claim';
    public const STEP_CONFIRMATION = 'confirmation';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** @var resource|string */
    #[ORM\Column(type: Types::BLOB, length: 16777215)]
    private $payload = '';

    #[ORM\Column]
    private int $payloadVersion = 1;

    #[ORM\Column]
    private int $version = 1;

    #[ORM\Column(length: 20)]
    private string $stepReached = self::STEP_DOCUMENTS;

    #[ORM\Column]
    private int $documentsCount = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $creditorName = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastActivityAt;

    public function __construct(User $user, ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();
        $this->user = $user;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->lastActivityAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /** The serialized bag as bytes, whether it was just set or read back as a stream. */
    public function getPayload(): string
    {
        if (is_resource($this->payload)) {
            rewind($this->payload);
            $contents = stream_get_contents($this->payload);
            $this->payload = $contents === false ? '' : $contents;
        }

        return (string) $this->payload;
    }

    public function setPayload(string $payload, int $payloadVersion): static
    {
        $this->payload = $payload;
        $this->payloadVersion = $payloadVersion;

        return $this;
    }

    public function getPayloadVersion(): int
    {
        return $this->payloadVersion;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getStepReached(): string
    {
        return $this->stepReached;
    }

    public function getDocumentsCount(): int
    {
        return $this->documentsCount;
    }

    public function getCreditorName(): ?string
    {
        return $this->creditorName;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastActivityAt(): \DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function touch(\DateTimeImmutable $now): static
    {
        $this->lastActivityAt = $now;

        return $this;
    }

    /**
     * Refreshes the list columns from the bag: how far the run got, how many
     * documents it holds and which creditor it names.
     */
    public function denormalizeFrom(WizardBag $bag, \DateTimeImmutable $now): static
    {
        $this->stepReached = self::stepReachedFor($bag);
        $this->documentsCount = count($bag->documentIds);
        $name = $bag->creditor?->name;
        $this->creditorName = $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null;
        $this->updatedAt = $now;
        $this->lastActivityAt = $now;

        return $this;
    }

    /**
     * The step the run is on, read from what it has saved: a saved claim means
     * the confirmation step was reached, a saved debtor the claim step, a saved
     * creditor the debtor step, otherwise the documents step.
     */
    public static function stepReachedFor(WizardBag $bag): string
    {
        return match (true) {
            $bag->claim !== null => self::STEP_CONFIRMATION,
            $bag->debtors !== null => self::STEP_CLAIM,
            $bag->creditor !== null => self::STEP_DEBTOR,
            default => self::STEP_DOCUMENTS,
        };
    }

    /** @return list<string> */
    public static function steps(): array
    {
        return [self::STEP_DOCUMENTS, self::STEP_CREDITOR, self::STEP_DEBTOR, self::STEP_CLAIM, self::STEP_CONFIRMATION];
    }
}
