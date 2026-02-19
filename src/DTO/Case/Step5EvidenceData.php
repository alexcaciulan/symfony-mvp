<?php

namespace App\DTO\Case;

use App\Entity\LegalCase;
use Symfony\Component\Validator\Constraints as Assert;

class Step5EvidenceData
{
    #[Assert\Length(max: 5000)]
    public ?string $evidenceDescription = null;

    public bool $hasWitnesses = false;

    /** @var Step5WitnessEntry[] */
    #[Assert\Valid]
    #[Assert\Count(max: 5, maxMessage: 'wizard.witnesses.max_five')]
    public array $witnesses = [];

    public bool $requestOralDebate = false;

    public function toWitnessesArray(): ?array
    {
        if (!$this->hasWitnesses || empty($this->witnesses)) {
            return null;
        }

        return array_map(fn(Step5WitnessEntry $w) => $w->toArray(), $this->witnesses);
    }

    public static function fromLegalCase(LegalCase $case): self
    {
        $dto = new self();
        $dto->evidenceDescription = $case->getEvidenceDescription();
        $dto->hasWitnesses = $case->hasWitnesses();
        $dto->requestOralDebate = $case->isRequestOralDebate();

        $witnesses = $case->getWitnesses();
        if ($witnesses && is_array($witnesses)) {
            foreach ($witnesses as $entry) {
                $dto->witnesses[] = Step5WitnessEntry::fromArray($entry);
            }
        }

        return $dto;
    }
}
