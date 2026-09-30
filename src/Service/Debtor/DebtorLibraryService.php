<?php

declare(strict_types=1);

namespace App\Service\Debtor;

use App\DTO\Library\DebtorLibraryData;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\PersonType;
use App\Repository\DebtorRepository;
use App\Service\AuditLogService;
use App\Service\Party\CuiNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes to the lawyer's debtor library. A company is shared by the cases that
 * name it, so an edit to what identifies it is recorded on each of those cases
 * too: a document regenerated there afterwards carries the new data. A new CUI
 * also clears the checks those cases hold, since they were made for the old one.
 */
final class DebtorLibraryService
{
    /** The fields that say who the debtor is and where it answers. */
    private const IDENTITY_FIELDS = ['Name', 'Cui', 'OnrcNumber', 'Address', 'AddressCounty', 'AddressLocality', 'Administrator'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DebtorRepository $debtors,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * Another company of this lawyer with the same CUI, if any. Editing a
     * company without changing its CUI is never a duplicate, even where rows
     * from before the library share a key.
     */
    public function findDuplicate(User $user, DebtorLibraryData $data, ?Debtor $current = null): ?Debtor
    {
        $key = CuiNormalizer::canonical($data->cui);
        if ($key === null || ($current !== null && $key === $current->getCuiKey())) {
            return null;
        }

        return $this->debtors->findOneByUserAndCuiKey($user, $key, $current);
    }

    /**
     * A new CUI is another company. Once a case has summoned this one, the
     * petition must name the company summoned, so the CUI can no longer move:
     * the other company is added to the library as a debtor of its own.
     */
    public function cuiChangeRefused(Debtor $debtor, DebtorLibraryData $data): bool
    {
        if (CuiNormalizer::canonical($data->cui) === $debtor->getCuiKey()) {
            return false;
        }
        return $this->hasSummonedCase($debtor);
    }

    /** Whether a case has already summoned this company (any case past AMIABIL). */
    public function hasSummonedCase(Debtor $debtor): bool
    {
        foreach ($debtor->getLegalCaseLinks() as $link) {
            if ($link->getLegalCase()->getStatus() !== CaseStatus::AMIABIL) {
                return true;
            }
        }

        return false;
    }

    public function create(User $user, DebtorLibraryData $data): Debtor
    {
        $debtor = new Debtor();
        $debtor->setUser($user);
        $debtor->setPersonType(PersonType::PJ);
        $this->apply($debtor, $data);
        $this->em->persist($debtor);
        $this->em->flush();

        $this->auditLog->log(action: 'debtor_created', entityType: Debtor::class, entityId: (string) $debtor->getId());
        $this->em->flush();

        return $debtor;
    }

    public function update(Debtor $debtor, DebtorLibraryData $data): void
    {
        $before = $this->identityOf($debtor);
        $this->apply($debtor, $data);
        $after = $this->identityOf($debtor);

        $changed = array_keys(array_diff_assoc($after, $before));
        $this->recordIdentityChangeOnCases($debtor, $before, $after);
        if (CuiNormalizer::canonical($before['cui']) !== $debtor->getCuiKey()) {
            // Another CUI is another company: what was checked for the old one
            // on each case no longer holds and has to be done again there.
            foreach ($debtor->getLegalCaseLinks() as $link) {
                $link->setAnafStatus(null);
                $link->setAnafCheckedAt(null);
                $link->setInsolvencyCheckedAt(null);
                $link->setInInsolvency(false);
                $link->setBpiProofDocument(null);
                $link->setBpiVerifiedNote(null);
            }
        }
        $this->auditLog->log(
            action: 'debtor_updated',
            entityType: Debtor::class,
            entityId: (string) $debtor->getId(),
            newData: ['fields' => $changed],
        );
        $this->em->flush();
    }

    /**
     * Fills the company's empty fields from a case's data, leaving every field
     * the library already holds as it is.
     */
    public function completeEmpty(Debtor $debtor, DebtorLibraryData $data): void
    {
        $before = $this->identityOf($debtor);
        $filled = [];
        // County and locality decide the court: they are only taken along
        // with the address they belong to.
        $sameAddress = trim((string) $data->address) === trim((string) $debtor->getAddress());
        foreach (['onrcNumber', 'addressCounty', 'addressLocality', 'administrator', 'email', 'phone', 'iban'] as $field) {
            if (!$sameAddress && in_array($field, ['addressCounty', 'addressLocality'], true)) {
                continue;
            }
            $current = $debtor->{'get' . ucfirst($field)}();
            $offered = $data->{$field};
            if (($current === null || $current === '') && $offered !== null && $offered !== '') {
                $debtor->{'set' . ucfirst($field)}($offered);
                $filled[] = $field;
            }
        }
        if ($filled === []) {
            return;
        }
        $this->auditLog->log(
            action: 'debtor_updated',
            entityType: Debtor::class,
            entityId: (string) $debtor->getId(),
            newData: ['fields' => $filled, 'source' => 'case'],
        );
        $this->recordIdentityChangeOnCases($debtor, $before);
    }

    /**
     * Deletes a company no case uses. One that a case names stays: the case is
     * the record of whom the lawyer pursued.
     */
    public function delete(Debtor $debtor): bool
    {
        if ($this->debtors->countLinks($debtor) > 0) {
            return false;
        }

        $id = (string) $debtor->getId();
        $this->em->remove($debtor);
        $this->auditLog->log(action: 'debtor_deleted', entityType: Debtor::class, entityId: $id);
        $this->em->flush();

        return true;
    }

    /**
     * Records, on every case naming the company, which of its identity fields
     * changed: a document regenerated there afterwards carries the new data.
     *
     * @param array<string, ?string> $before
     * @param ?array<string, ?string> $after
     */
    private function recordIdentityChangeOnCases(Debtor $debtor, array $before, ?array $after = null): void
    {
        $after ??= $this->identityOf($debtor);
        $changed = array_keys(array_diff_assoc($after, $before));
        if ($changed === []) {
            return;
        }
        $old = array_intersect_key($before, array_flip($changed));
        $new = array_intersect_key($after, array_flip($changed));
        foreach ($this->debtors->casesUsing($debtor) as $case) {
            $this->auditLog->log(
                action: 'debtor_identity_changed',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                oldData: $old,
                newData: $new,
            );
        }
    }

    private function apply(Debtor $debtor, DebtorLibraryData $data): void
    {
        // The form's NotBlank constraints have run by the time this is called.
        $debtor->setName((string) $data->name);
        $debtor->setCui($data->cui);
        $debtor->setOnrcNumber($data->onrcNumber);
        $debtor->setAddress((string) $data->address);
        $debtor->setAddressCounty($data->addressCounty);
        $debtor->setAddressLocality($data->addressLocality);
        $debtor->setAdministrator($data->administrator);
        $debtor->setEmail($data->email);
        $debtor->setPhone($data->phone);
        $debtor->setIban($data->iban);
    }

    /**
     * @return array<string, ?string>
     */
    private function identityOf(Debtor $debtor): array
    {
        $identity = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            $identity[lcfirst($field)] = $debtor->{'get' . $field}();
        }

        return $identity;
    }
}
