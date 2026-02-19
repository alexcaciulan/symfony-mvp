<?php

namespace App\DTO\Case;

use Symfony\Component\Validator\Constraints as Assert;

class Step3DefendantsData
{
    /** @var Step3DefendantEntry[] */
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'wizard.defendants.min_one_required', max: 3, maxMessage: 'wizard.defendants.max_three')]
    public array $defendants = [];

    public function toArray(): array
    {
        return array_map(fn(Step3DefendantEntry $d) => $d->toArray(), $this->defendants);
    }

    public static function fromLegalCase(\App\Entity\LegalCase $case): self
    {
        $dto = new self();

        $data = $case->getDefendants();
        if ($data && is_array($data)) {
            foreach ($data as $entry) {
                $dto->defendants[] = Step3DefendantEntry::fromArray($entry);
            }
        }

        if (empty($dto->defendants)) {
            $dto->defendants[] = new Step3DefendantEntry();
        }

        return $dto;
    }
}
