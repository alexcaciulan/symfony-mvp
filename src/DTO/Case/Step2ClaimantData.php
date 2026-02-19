<?php

namespace App\DTO\Case;

use Symfony\Component\Validator\Constraints as Assert;

class Step2ClaimantData
{
    #[Assert\NotBlank(message: 'wizard.claimant.type_required')]
    #[Assert\Choice(choices: ['pf', 'pj'], message: 'wizard.claimant.type_invalid')]
    public ?string $type = 'pf';

    #[Assert\NotBlank(message: 'wizard.claimant.name_required')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Length(exactly: 13, exactMessage: 'wizard.claimant.cnp_length', groups: ['pf'])]
    public ?string $cnp = null;

    #[Assert\Length(max: 20)]
    public ?string $cui = null;

    #[Assert\Length(max: 255)]
    public ?string $companyName = null;

    #[Assert\NotBlank(message: 'wizard.claimant.email_required')]
    #[Assert\Email(message: 'wizard.claimant.email_invalid')]
    public ?string $email = null;

    #[Assert\Length(max: 20)]
    public ?string $phone = null;

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

    #[Assert\Length(max: 100)]
    public ?string $city = null;

    #[Assert\Length(max: 50)]
    public ?string $county = null;

    #[Assert\Length(max: 10)]
    public ?string $postalCode = null;

    public bool $hasLawyer = false;

    #[Assert\Length(max: 255)]
    public ?string $lawyerName = null;

    #[Assert\Length(max: 20)]
    public ?string $lawyerPhone = null;

    #[Assert\Email]
    public ?string $lawyerEmail = null;

    #[Assert\Length(max: 50)]
    public ?string $lawyerBarNumber = null;

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'cnp' => $this->cnp,
            'cui' => $this->cui,
            'companyName' => $this->companyName,
            'email' => $this->email,
            'phone' => $this->phone,
            'street' => $this->street,
            'streetNumber' => $this->streetNumber,
            'block' => $this->block,
            'staircase' => $this->staircase,
            'apartment' => $this->apartment,
            'city' => $this->city,
            'county' => $this->county,
            'postalCode' => $this->postalCode,
        ];
    }

    public function toLawyerArray(): ?array
    {
        if (!$this->hasLawyer) {
            return null;
        }

        return [
            'name' => $this->lawyerName,
            'phone' => $this->lawyerPhone,
            'email' => $this->lawyerEmail,
            'barNumber' => $this->lawyerBarNumber,
        ];
    }

    public static function fromLegalCase(\App\Entity\LegalCase $case, \App\Entity\User $user): self
    {
        $dto = new self();

        if ($case->getClaimantData() !== null) {
            $data = $case->getClaimantData();
            $dto->type = $case->getClaimantType() ?? 'pf';
            $dto->name = $data['name'] ?? null;
            $dto->cnp = $data['cnp'] ?? null;
            $dto->cui = $data['cui'] ?? null;
            $dto->companyName = $data['companyName'] ?? null;
            $dto->email = $data['email'] ?? null;
            $dto->phone = $data['phone'] ?? null;
            $dto->street = $data['street'] ?? null;
            $dto->streetNumber = $data['streetNumber'] ?? null;
            $dto->block = $data['block'] ?? null;
            $dto->staircase = $data['staircase'] ?? null;
            $dto->apartment = $data['apartment'] ?? null;
            $dto->city = $data['city'] ?? null;
            $dto->county = $data['county'] ?? null;
            $dto->postalCode = $data['postalCode'] ?? null;

            $dto->hasLawyer = $case->hasLawyer();
            $lawyerData = $case->getLawyerData();
            if ($lawyerData) {
                $dto->lawyerName = $lawyerData['name'] ?? null;
                $dto->lawyerPhone = $lawyerData['phone'] ?? null;
                $dto->lawyerEmail = $lawyerData['email'] ?? null;
                $dto->lawyerBarNumber = $lawyerData['barNumber'] ?? null;
            }
        } else {
            $dto->type = $user->getType()?->value ?? 'pf';
            $dto->name = $user->getFullName() ?: null;
            $dto->email = $user->getEmail();
            $dto->phone = $user->getPhone();
            $dto->cnp = $user->getCnp();
            $dto->cui = $user->getCui();
            $dto->companyName = $user->getCompanyName();
            $dto->street = $user->getStreet();
            $dto->streetNumber = $user->getStreetNumber();
            $dto->block = $user->getBlock();
            $dto->staircase = $user->getStaircase();
            $dto->apartment = $user->getApartment();
            $dto->city = $user->getCity();
            $dto->county = $user->getCounty();
            $dto->postalCode = $user->getPostalCode();
        }

        return $dto;
    }
}
