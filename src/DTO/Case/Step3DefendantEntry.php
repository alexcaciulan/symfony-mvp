<?php

namespace App\DTO\Case;

use Symfony\Component\Validator\Constraints as Assert;

class Step3DefendantEntry
{
    #[Assert\NotBlank(message: 'wizard.defendant.type_required')]
    #[Assert\Choice(choices: ['pf', 'pj'])]
    public ?string $type = 'pf';

    #[Assert\NotBlank(message: 'wizard.defendant.name_required')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Length(max: 13)]
    public ?string $cnp = null;

    #[Assert\Length(max: 20)]
    public ?string $cui = null;

    #[Assert\Length(max: 255)]
    public ?string $companyName = null;

    #[Assert\Length(max: 255)]
    public ?string $street = null;

    #[Assert\Length(max: 20)]
    public ?string $streetNumber = null;

    #[Assert\Length(max: 20)]
    public ?string $block = null;

    #[Assert\Length(max: 10)]
    public ?string $staircase = null;

    #[Assert\Length(max: 10)]
    public ?string $apartment = null;

    #[Assert\NotBlank(message: 'wizard.defendant.city_required')]
    #[Assert\Length(max: 100)]
    public ?string $city = null;

    #[Assert\NotBlank(message: 'wizard.defendant.county_required')]
    #[Assert\Length(max: 50)]
    public ?string $county = null;

    #[Assert\Length(max: 10)]
    public ?string $postalCode = null;

    #[Assert\Email]
    public ?string $email = null;

    #[Assert\Length(max: 20)]
    public ?string $phone = null;

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'cnp' => $this->cnp,
            'cui' => $this->cui,
            'companyName' => $this->companyName,
            'street' => $this->street,
            'streetNumber' => $this->streetNumber,
            'block' => $this->block,
            'staircase' => $this->staircase,
            'apartment' => $this->apartment,
            'city' => $this->city,
            'county' => $this->county,
            'postalCode' => $this->postalCode,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }

    public static function fromArray(array $data): self
    {
        $entry = new self();
        $entry->type = $data['type'] ?? 'pf';
        $entry->name = $data['name'] ?? null;
        $entry->cnp = $data['cnp'] ?? null;
        $entry->cui = $data['cui'] ?? null;
        $entry->companyName = $data['companyName'] ?? null;
        $entry->street = $data['street'] ?? null;
        $entry->streetNumber = $data['streetNumber'] ?? null;
        $entry->block = $data['block'] ?? null;
        $entry->staircase = $data['staircase'] ?? null;
        $entry->apartment = $data['apartment'] ?? null;
        $entry->city = $data['city'] ?? null;
        $entry->county = $data['county'] ?? null;
        $entry->postalCode = $data['postalCode'] ?? null;
        $entry->email = $data['email'] ?? null;
        $entry->phone = $data['phone'] ?? null;

        return $entry;
    }
}
