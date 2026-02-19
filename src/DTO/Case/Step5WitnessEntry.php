<?php

namespace App\DTO\Case;

use Symfony\Component\Validator\Constraints as Assert;

class Step5WitnessEntry
{
    #[Assert\NotBlank(message: 'wizard.witness.name_required')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Length(max: 255)]
    public ?string $address = null;

    #[Assert\Length(max: 500)]
    public ?string $details = null;

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'details' => $this->details,
        ];
    }

    public static function fromArray(array $data): self
    {
        $entry = new self();
        $entry->name = $data['name'] ?? null;
        $entry->address = $data['address'] ?? null;
        $entry->details = $data['details'] ?? null;

        return $entry;
    }
}
