<?php

declare(strict_types=1);

namespace App\Service\Creditor;

use App\DTO\Wizard\Step1CreditorData;
use App\Entity\Creditor;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Repository\CreditorRepository;
use App\Service\AuditLogService;
use App\Service\Party\CuiNormalizer;

/**
 * The lawyer's library of creditors, under the same rules as the debtor
 * library: a company is one row per lawyer, found by its canonical CUI. It is
 * shared by every case that names it, so an edit to what identifies it is
 * recorded on each of those cases, and its CUI no longer moves once a somatie
 * went out under it.
 */
final class CreditorLibraryService
{
    /** The fields that say who the creditor is, as the acts print it. */
    private const IDENTITY_FIELDS = ['Name', 'Cui', 'OnrcNumber', 'Address', 'AddressCounty', 'AddressLocality', 'LegalRepresentative', 'Iban'];

    /** Filled on an existing row only where it is empty. */
    private const COMPLETED_FIELDS = ['OnrcNumber', 'LegalRepresentative', 'Iban', 'BankName'];

    public function __construct(
        private readonly CreditorRepository $creditors,
        private readonly AuditLogService $auditLog,
    ) {}

    /** Another creditor of this lawyer with the same CUI, if any. */
    public function findDuplicate(User $user, ?string $cui, ?Creditor $current = null): ?Creditor
    {
        $key = CuiNormalizer::canonical($cui);
        if ($key === null || ($current !== null && $current->getCuiKey() === $key)) {
            return null;
        }
        $existing = $this->creditors->findOneByUserAndCuiKey($user, $key);

        return $existing === $current ? null : $existing;
    }

    public function hasSummonedCase(Creditor $creditor): bool
    {
        return $this->creditors->hasSummonedCase($creditor);
    }

    /** A new CUI is another company: refused once a somatie names this one. */
    public function cuiChangeRefused(Creditor $creditor, ?string $cui): bool
    {
        return CuiNormalizer::canonical($cui) !== $creditor->getCuiKey() && $this->hasSummonedCase($creditor);
    }

    /** Writes every field the data carries over the company, as an edit does. */
    public function update(Creditor $creditor, Step1CreditorData $data): void
    {
        $before = $this->identityOf($creditor);
        $creditor->setPersonType($data->personType ?? $creditor->getPersonType());
        $creditor->setName((string) $data->name);
        $creditor->setAddress((string) $data->address);
        $creditor->setAddressCounty($data->addressCounty);
        $creditor->setAddressLocality($data->addressLocality);
        $creditor->setCui($data->cui);
        $creditor->setPersonalId($data->personalId);
        $creditor->setOnrcNumber($data->onrcNumber);
        $creditor->setIban($data->iban);
        $creditor->setBankName($data->bankName);
        $creditor->setLegalRepresentative($data->legalRepresentative);
        $this->recordChange($creditor, $before, 'creditor_updated');
    }

    /**
     * Fills only what the company leaves empty. County and locality come only
     * with the address they belong to: they decide where the stamp duty is paid.
     */
    public function completeEmpty(Creditor $creditor, Step1CreditorData $data): void
    {
        $before = $this->identityOf($creditor);
        $fields = self::COMPLETED_FIELDS;
        if (trim((string) $data->address) === trim($creditor->getAddress())) {
            $fields = [...$fields, 'AddressCounty', 'AddressLocality'];
        }
        foreach ($fields as $field) {
            $current = $creditor->{'get' . $field}();
            $offered = $data->{lcfirst($field)};
            if (($current === null || $current === '') && $offered !== null && $offered !== '') {
                $creditor->{'set' . $field}($offered);
            }
        }
        $this->recordChange($creditor, $before, 'creditor_updated');
    }

    /** @param array<string, ?string> $before */
    private function recordChange(Creditor $creditor, array $before, string $action): void
    {
        $after = $this->identityOf($creditor);
        $changed = array_keys(array_diff_assoc($after, $before));
        if ($changed === []) {
            return;
        }
        $old = array_intersect_key($before, array_flip($changed));
        $new = array_intersect_key($after, array_flip($changed));
        if ($creditor->getId() !== null) {
            $this->auditLog->log(action: $action, entityType: Creditor::class, entityId: (string) $creditor->getId(), oldData: $old, newData: $new);
        }
        foreach ($this->creditors->casesUsing($creditor) as $case) {
            $this->auditLog->log(
                action: 'creditor_identity_changed',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                oldData: $old,
                newData: $new,
            );
        }
    }

    /** @return array<string, ?string> */
    private function identityOf(Creditor $creditor): array
    {
        $identity = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            $identity[lcfirst($field)] = $creditor->{'get' . $field}();
        }

        return $identity;
    }
}
