<?php

declare(strict_types=1);

namespace App\DTO\Billing;

use App\Entity\User;
use App\Enum\UserType;

/**
 * Immutable snapshot of one party (supplier or buyer) frozen onto a fiscal
 * invoice at issuance. Persisted as a JSON column and rehydrated on read, so a
 * later edit of the source User never rewrites historical invoices.
 */
final readonly class PartySnapshot
{
    public function __construct(
        public string $name,
        public ?string $cui = null,
        public ?string $personalId = null,
        public ?string $onrcNumber = null,
        public string $address = '',
        public ?string $iban = null,
        public ?string $email = null,
        public ?string $phone = null,
        public string $country = 'RO',
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'cui' => $this->cui,
            'personalId' => $this->personalId,
            'onrcNumber' => $this->onrcNumber,
            'address' => $this->address,
            'iban' => $this->iban,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            cui: $data['cui'] ?? null,
            personalId: $data['personalId'] ?? null,
            onrcNumber: $data['onrcNumber'] ?? null,
            address: (string) ($data['address'] ?? ''),
            iban: $data['iban'] ?? null,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            country: (string) ($data['country'] ?? 'RO'),
        );
    }

    /** Build the buyer snapshot from the account holder's fiscal profile. */
    public static function fromUser(User $user): self
    {
        $isCompany = in_array($user->getType(), [UserType::PJ, UserType::AVOCAT], true);
        $name = $isCompany && null !== $user->getCompanyName()
            ? $user->getCompanyName()
            : trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? ''));

        return new self(
            name: '' !== $name ? $name : ($user->getEmail() ?? ''),
            cui: $user->getCui(),
            personalId: $user->getCnp(),
            onrcNumber: null,
            address: self::composeAddress($user),
            iban: null,
            email: $user->getEmail(),
            phone: $user->getPhone(),
        );
    }

    private static function composeAddress(User $user): string
    {
        $street = trim(implode(' ', array_filter([
            $user->getStreet(),
            $user->getStreetNumber() ? 'nr. ' . $user->getStreetNumber() : null,
            $user->getBlock() ? 'bl. ' . $user->getBlock() : null,
            $user->getStaircase() ? 'sc. ' . $user->getStaircase() : null,
            $user->getApartment() ? 'ap. ' . $user->getApartment() : null,
        ])));

        $locality = trim(implode(', ', array_filter([
            $user->getCity(),
            $user->getCounty(),
            $user->getPostalCode(),
        ])));

        return trim(implode(', ', array_filter([$street, $locality])));
    }
}
