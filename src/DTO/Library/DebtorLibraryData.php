<?php

declare(strict_types=1);

namespace App\DTO\Library;

use App\Entity\Debtor;
use App\Validator\Constraints\ValidCui;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company in the lawyer's debtor library: identity and contact only. What is
 * checked about a debtor (ANAF status, the Law 85/2014 attestation) belongs to a
 * case and is never kept here. Legal persons only, like the wizard for now.
 */
class DebtorLibraryData
{
    public function __construct(
        #[Assert\NotBlank(message: 'wizard.step2.error.name_required')]
        #[Assert\Length(max: 255)]
        public ?string $name = null,
        #[Assert\NotBlank(message: 'wizard.step2.error.cui_required')]
        #[ValidCui]
        public ?string $cui = null,
        #[Assert\NotBlank(message: 'wizard.step2.error.onrc_required')]
        #[Assert\Length(max: 50)]
        public ?string $onrcNumber = null,
        #[Assert\NotBlank(message: 'wizard.step2.error.address_required')]
        public ?string $address = null,
        #[Assert\Length(max: 100)]
        public ?string $addressCounty = null,
        #[Assert\Length(max: 150)]
        public ?string $addressLocality = null,
        #[Assert\Length(max: 255)]
        public ?string $administrator = null,
        #[Assert\Email(message: 'validation.email.invalid')]
        public ?string $email = null,
        #[Assert\Length(max: 30)]
        public ?string $phone = null,
        #[Assert\Regex(pattern: '/^RO\d{2}[A-Z]{4}[A-Z0-9]{16}$/', message: 'validation.iban.invalid_format')]
        public ?string $iban = null,
    ) {}

    public static function fromDebtor(Debtor $debtor): self
    {
        return new self(
            name: $debtor->getName(),
            cui: $debtor->getCui(),
            onrcNumber: $debtor->getOnrcNumber(),
            address: $debtor->getAddress(),
            addressCounty: $debtor->getAddressCounty(),
            addressLocality: $debtor->getAddressLocality(),
            administrator: $debtor->getAdministrator(),
            email: $debtor->getEmail(),
            phone: $debtor->getPhone(),
            iban: $debtor->getIban(),
        );
    }
}
