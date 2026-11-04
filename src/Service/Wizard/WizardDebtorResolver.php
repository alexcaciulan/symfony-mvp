<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\DTO\Library\DebtorLibraryData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\Entity\Debtor;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\PersonType;
use App\Repository\DebtorRepository;
use App\Service\Company\AnafLookupException;
use App\Service\Company\AnafLookupService;
use App\Service\Debtor\DebtorLibraryService;
use App\Service\Party\CuiNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reconciles the debtors of a wizard run with the lawyer's debtor library and
 * the register: at step 2, again at confirmation, and when the case is written.
 */
final class WizardDebtorResolver
{
    /** The identity and contact fields a library company and a debtor entry share. */
    private const LIBRARY_FIELDS = ['name', 'onrcNumber', 'address', 'addressCounty', 'addressLocality', 'administrator', 'email', 'phone', 'iban'];

    public function __construct(
        private readonly DebtorRepository $debtorLibrary,
        private readonly DebtorLibraryService $debtorLibraryService,
        private readonly LibraryFieldComparer $comparer,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?AnafLookupService $anafLookup = null,
    ) {}

    /**
     * Fetches the fiscal status of each company debtor the lawyer did not sync.
     *
     * Whether the debtor is active, inactive or struck off is a fact the
     * register holds, not a judgment the lawyer makes, so the confirmation step
     * looks it up itself rather than asking the lawyer to go back two steps and
     * press a button. Only the status is taken: the debtor's name and seat stay
     * what the lawyer confirmed. A register that does not answer leaves the
     * status unknown, which the admissibility check still reports.
     */
    public function checkAnafStatus(Step2DebtorsData $debtors): bool
    {
        if ($this->anafLookup === null) {
            return false;
        }

        $changed = false;
        foreach ($debtors->debtors as $entry) {
            if ($entry->personType !== PersonType::PJ || $entry->anafStatus !== null
                || $entry->cui === null || trim($entry->cui) === '') {
                continue;
            }
            try {
                $status = AnafStatus::tryFrom($this->anafLookup->lookupByCui($entry->cui)['stare']);
            } catch (AnafLookupException $e) {
                $this->logger->info('wizard.anaf.status_check_failed', ['reason' => $e->getMessage()]);

                continue;
            }
            if ($status === null) {
                continue;
            }
            $entry->anafStatus = $status;
            $entry->anafCheckedAt = new \DateTimeImmutable();
            $changed = true;
        }

        return $changed;
    }

    /**
     * A debtor typed or extracted in this step may be a company the library
     * already holds (same CUI). Then the case links to that company, so the
     * acts would carry the library's data: when the two differ, the lawyer
     * decides, entry by entry, which data stands, never the wizard alone.
     * Updating the library is not offered for a company another case has
     * already summoned: its petition must name the company as summoned.
     *
     * @param array<array-key, mixed> $choices entry index to 'library' or 'update'
     * @return array<int, array{name: string, differs: array<string, array{?string, ?string}>, canUpdate: bool}>
     *         the entries still waiting for that decision; empty when none
     */
    public function matchLibraryDebtors(Step2DebtorsData $debtors, User $user, array $choices): array
    {
        $pending = [];
        foreach ($debtors->debtors as $index => $entry) {
            $entry->updateLibrary = false;
            $key = CuiNormalizer::canonical($entry->cui);

            // A link whose CUI no longer matches its company is another company now.
            $company = $entry->debtorId !== null ? $this->debtorLibrary->findOwned($user, $entry->debtorId) : null;
            if ($company !== null && $company->getCuiKey() !== $key) {
                $company = null;
            }
            if ($company === null) {
                $entry->debtorId = null;
                if ($key === null || $entry->personType !== PersonType::PJ) {
                    continue;
                }
                $company = $this->debtorLibrary->findOneByUserAndCuiKey($user, $key);
                if ($company === null) {
                    continue;
                }
            }

            $differs = $this->libraryDifferences($entry, $company);

            $canUpdate = !$this->debtorLibraryService->hasSummonedCase($company);
            $choice = is_string($choices[$index] ?? null) ? $choices[$index] : '';
            $decided = $choice === 'library' || ($choice === 'update' && $canUpdate);
            if ($differs !== [] && !$decided) {
                $pending[$index] = ['name' => $company->getName(), 'differs' => $differs, 'canUpdate' => $canUpdate];

                continue;
            }
            $entry->debtorId = $company->getId();
            $entry->updateLibrary = $differs !== [] && $choice === 'update';
        }

        return $pending;
    }

    /**
     * A debtor typed at step 2 whose company reached the library afterwards (a
     * second tab, the library page): linked when nothing differs, as step 2
     * would have done; otherwise the lawyer goes back to choose which data
     * stands, instead of a second row being created at submission.
     */
    public function debtorReachedLibrary(Step2DebtorsData $debtors, User $user): bool
    {
        foreach ($debtors->debtors as $entry) {
            if ($entry->debtorId !== null || $entry->personType !== PersonType::PJ) {
                continue;
            }
            $key = CuiNormalizer::canonical($entry->cui);
            $company = $key !== null ? $this->debtorLibrary->findOneByUserAndCuiKey($user, $key) : null;
            if ($company === null) {
                continue;
            }
            if ($this->libraryDifferences($entry, $company) !== []) {
                return true;
            }
            $entry->debtorId = $company->getId();
        }

        return false;
    }

    /**
     * A posted library link is kept only for a company of this lawyer whose
     * CUI still matches, and then the identity is the library's whatever the
     * page sent: the case will name that company, not a copy edited on the way.
     */
    public function bindLibraryDebtors(Step2DebtorsData $debtors, User $user): void
    {
        foreach ($debtors->debtors as $entry) {
            if ($entry->debtorId === null) {
                continue;
            }
            $company = $this->debtorLibrary->findOwned($user, $entry->debtorId);
            if ($company === null || $company->getCuiKey() === null
                || $company->getCuiKey() !== CuiNormalizer::canonical($entry->cui)) {
                $entry->debtorId = null;

                continue;
            }
            // A summoned company is not changed from the wizard, not even its
            // empty fields: the case reads its debtor from the company, and the
            // petition must name the company as it was summoned.
            $frozen = $this->debtorLibraryService->hasSummonedCase($company);
            if ($frozen) {
                $entry->updateLibrary = false;
            }
            $library = $this->entryFromLibrary($company);
            // County and locality go with the address they belong to: they
            // decide the court, so they are taken from this step only with it.
            $sameAddress = trim((string) $entry->address) === trim((string) $library->address);
            foreach (['personType', 'name', 'cui', 'onrcNumber', 'address', 'addressCounty', 'addressLocality', 'email', 'phone', 'iban', 'administrator'] as $field) {
                // The library's data stands, except where the lawyer chose this
                // step's data to update it; an empty library field takes this
                // step's value otherwise.
                $mine = $entry->{$field};
                $fillsEmpty = !$frozen
                    && ($library->{$field} === null || $library->{$field} === '')
                    && ($sameAddress || !in_array($field, ['addressCounty', 'addressLocality'], true));
                $useMine = $mine !== null && $mine !== '' && ($entry->updateLibrary || $fillsEmpty);
                $entry->{$field} = $useMine ? $mine : $library->{$field};
            }
        }
    }

    /**
     * A debtor picked from the library shows the library's identity read-only,
     * so it follows the company when the company is corrected there (the way
     * out of a stored value that fails the checks), except where the lawyer
     * chose this step's data to update it. A new CUI makes it another company
     * for the checks: what was verified for the old one is withdrawn.
     */
    public function refreshPickedDebtors(Step2DebtorsData $debtors, User $user): void
    {
        foreach ($debtors->debtors as $entry) {
            if ($entry->debtorId === null) {
                continue;
            }
            $company = $this->debtorLibrary->findOwned($user, $entry->debtorId);
            if ($company === null) {
                $entry->debtorId = null;

                continue;
            }
            $cuiChanged = $company->getCuiKey() !== CuiNormalizer::canonical($entry->cui);
            // The lawyer chose this step's data to update the company with.
            if (!$cuiChanged && $entry->updateLibrary) {
                continue;
            }
            $library = $this->entryFromLibrary($company);
            foreach (['personType', 'name', 'cui', 'onrcNumber', 'address', 'addressCounty', 'addressLocality', 'email', 'phone', 'iban', 'administrator'] as $field) {
                // An empty library field keeps what this step filled it with.
                if ($cuiChanged || ($library->{$field} !== null && $library->{$field} !== '')) {
                    $entry->{$field} = $library->{$field};
                }
            }
            if (!$cuiChanged) {
                continue;
            }
            $entry->insolvencyCheckedAt = null;
            $entry->inInsolvency = false;
            $entry->anafStatus = null;
            $entry->anafCheckedAt = null;
            $entry->autoFilled = [];
            $entry->updateLibrary = false;
        }
    }

    public function entryFromLibrary(Debtor $debtor): Step2DebtorEntry
    {
        return new Step2DebtorEntry(
            personType: $debtor->getPersonType(),
            name: $debtor->getName(),
            cui: $debtor->getCui(),
            onrcNumber: $debtor->getOnrcNumber(),
            address: $debtor->getAddress(),
            addressCounty: $debtor->getAddressCounty(),
            addressLocality: $debtor->getAddressLocality(),
            email: $debtor->getEmail(),
            phone: $debtor->getPhone(),
            iban: $debtor->getIban(),
            administrator: $debtor->getAdministrator(),
            debtorId: $debtor->getId(),
        );
    }

    /**
     * The debtor of the case: the company (identity), and its link to the case
     * carrying what was checked for this case.
     *
     * @param bool $write whether the library company may be written (the
     *        submission); the preview built for validation never writes
     */
    public function buildDebtorLink(User $user, Step2DebtorEntry $entry, bool $write = true): LegalCaseDebtor
    {
        $picked = $entry->debtorId !== null ? $this->debtorLibrary->findOwned($user, $entry->debtorId) : null;
        if ($picked !== null && $picked->getCuiKey() !== null && $picked->getCuiKey() === CuiNormalizer::canonical($entry->cui)) {
            if ($write) {
                $data = $this->libraryDataFrom($entry);
                if (!$this->debtorLibraryService->hasSummonedCase($picked)) {
                    if ($entry->updateLibrary) {
                        $this->debtorLibraryService->update($picked, $data);
                    } else {
                        $this->debtorLibraryService->completeEmpty($picked, $data);
                    }
                }
            }

            return $this->linkWithChecks(new LegalCaseDebtor($picked), $entry);
        }

        $debtor = new Debtor();
        $debtor->setUser($user);
        // The DTO NotNull/NotBlank on personType/name/address has already
        // fired by the time the case is written; trust the contract.
        $debtor->setPersonType($entry->personType);
        $debtor->setName($entry->name);
        $debtor->setAddress($entry->address);
        $debtor->setAddressCounty($entry->addressCounty);
        $debtor->setAddressLocality($entry->addressLocality);
        $debtor->setCui($entry->cui);
        $debtor->setPersonalId($entry->personalId);
        $debtor->setOnrcNumber($entry->onrcNumber);
        $debtor->setEmail($entry->email);
        $debtor->setPhone($entry->phone);
        $debtor->setIban($entry->iban);
        $debtor->setAdministrator($entry->administrator);

        return $this->linkWithChecks(new LegalCaseDebtor($debtor), $entry);
    }

    /**
     * The fields both sides fill differently, field to [library, step].
     *
     * @return array<string, array{?string, ?string}>
     */
    private function libraryDifferences(Step2DebtorEntry $entry, Debtor $company): array
    {
        $differs = [];
        $library = $this->entryFromLibrary($company);
        foreach (self::LIBRARY_FIELDS as $field) {
            $mine = trim((string) $entry->{$field});
            $theirs = trim((string) $library->{$field});
            if ($mine !== '' && $theirs !== '' && !$this->comparer->same($field, $mine, $theirs)) {
                $differs[$field] = [$library->{$field}, $entry->{$field}];
            }
        }

        return $differs;
    }

    private function libraryDataFrom(Step2DebtorEntry $entry): DebtorLibraryData
    {
        return new DebtorLibraryData(
            name: $entry->name,
            cui: $entry->cui,
            onrcNumber: $entry->onrcNumber,
            address: $entry->address,
            addressCounty: $entry->addressCounty,
            addressLocality: $entry->addressLocality,
            administrator: $entry->administrator,
            email: $entry->email,
            phone: $entry->phone,
            iban: $entry->iban,
        );
    }

    /** What was checked about the debtor for this case, on the case's link. */
    private function linkWithChecks(LegalCaseDebtor $link, Step2DebtorEntry $entry): LegalCaseDebtor
    {
        $link->setAnafStatus($entry->anafStatus);
        $link->setAnafCheckedAt($entry->anafCheckedAt);
        $link->setInInsolvency($entry->inInsolvency);
        $link->setInsolvencyCheckedAt($entry->insolvencyCheckedAt);

        return $link;
    }
}
