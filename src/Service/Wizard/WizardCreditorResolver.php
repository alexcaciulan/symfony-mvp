<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\DTO\Wizard\CreditorResolution;
use App\DTO\Wizard\Step1CreditorData;
use App\Entity\Creditor;
use App\Entity\User;
use App\Enum\PersonType;
use App\Repository\CreditorRepository;
use App\Service\Creditor\CreditorLibraryService;
use App\Service\Party\CuiNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reconciles the creditor of a wizard run with the lawyer's creditor library:
 * at step 1 and again at confirmation, and when the case is written.
 */
final class WizardCreditorResolver
{
    /** The identity and contact fields a library creditor and the step share. */
    private const CREDITOR_LIBRARY_FIELDS = ['name', 'onrcNumber', 'address', 'addressCounty', 'addressLocality', 'legalRepresentative', 'iban', 'bankName'];

    public function __construct(
        private readonly CreditorRepository $creditors,
        private readonly CreditorLibraryService $creditorLibrary,
        private readonly LibraryFieldComparer $comparer,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * A creditor typed or extracted at step 1 may be a company the library
     * already holds (same canonical CUI). When nothing differs the case takes
     * that company; otherwise the lawyer chooses which data stands, and the
     * library is only updated on an explicit choice and never for a company a
     * somatie already went out under. Returns what to ask, or null when settled.
     *
     * @return ?array{name: string, differs: array<string, array{?string, ?string}>, canUpdate: bool}
     */
    public function matchLibraryCreditor(Step1CreditorData $dto, User $user, string $choice): ?array
    {
        $dto->updateLibrary = false;
        if ($dto->creditorId !== null || $dto->personType !== PersonType::PJ) {
            return null;
        }
        $key = CuiNormalizer::canonical($dto->cui);
        $company = $key !== null ? $this->creditors->findOneByUserAndCuiKey($user, $key) : null;
        if ($company === null) {
            return null;
        }

        $differs = $this->creditorDifferences($dto, $company);
        $canUpdate = !$this->creditorLibrary->hasSummonedCase($company);
        $decided = $choice === 'library' || ($choice === 'update' && $canUpdate);
        if ($differs !== [] && !$decided) {
            return ['name' => $company->getName(), 'differs' => $differs, 'canUpdate' => $canUpdate];
        }
        $dto->creditorId = $company->getId();
        $dto->updateLibrary = $differs !== [] && $choice === 'update';
        if (!$dto->updateLibrary) {
            $this->takeLibraryCreditor($dto, $company, strict: !$canUpdate);
        }

        return null;
    }

    /**
     * At step 4: a creditor taken from the library shows the library's data
     * (it may have been edited since), unless the lawyer chose to update the
     * library with this step's data; a creditor typed at step 1 whose company
     * reached the library afterwards is linked when nothing differs. Returns
     * true when the lawyer has to go back and choose.
     */
    public function settleCreditorWithLibrary(Step1CreditorData $dto, User $user): bool
    {
        if ($dto->creditorId !== null) {
            $company = $this->creditors->findOwned($user, $dto->creditorId);
            if ($company === null) {
                $dto->creditorId = null;
                $dto->updateLibrary = false;
            } elseif (!$dto->updateLibrary) {
                $this->takeLibraryCreditor($dto, $company, strict: $this->creditorLibrary->hasSummonedCase($company));
            }

            return false;
        }
        if ($dto->personType !== PersonType::PJ) {
            return false;
        }
        $key = CuiNormalizer::canonical($dto->cui);
        $company = $key !== null ? $this->creditors->findOneByUserAndCuiKey($user, $key) : null;
        if ($company === null) {
            return false;
        }
        if ($this->creditorDifferences($dto, $company) !== []) {
            return true;
        }
        $dto->creditorId = $company->getId();
        $this->takeLibraryCreditor($dto, $company, strict: $this->creditorLibrary->hasSummonedCase($company));

        return false;
    }

    /**
     * The creditor the case will name. A creditor from the library (picked, or
     * matched by CUI at step 1) is written only as the lawyer chose: updated
     * with this step's data, or completed where it is empty. A company a
     * somatie already went out under is left as it is. Otherwise a new creditor
     * is persisted from the step's data. Meant to run inside the submission's
     * transaction: the result says whether the library was reused, so the
     * caller can tell the lawyer after the commit, not before.
     */
    public function reuseOrCreateCreditor(User $user, Step1CreditorData $dto): CreditorResolution
    {
        $existing = $dto->creditorId !== null ? $this->creditors->findOwned($user, $dto->creditorId) : null;
        if ($existing !== null) {
            if (!$this->creditorLibrary->hasSummonedCase($existing)) {
                if ($dto->updateLibrary) {
                    $this->creditorLibrary->update($existing, $dto);
                } else {
                    $this->creditorLibrary->completeEmpty($existing, $dto);
                }
                $checkedAt = $this->parseAnafCheckedAt($dto->anafCheckedAt);
                if ($checkedAt !== null && $existing->getAddressLocality() !== null) {
                    $existing->setAnafCheckedAt($checkedAt);
                }
            }

            return new CreditorResolution($existing, true, $dto->updateLibrary ? 'library_updated' : 'library');
        }

        $creditor = new Creditor();
        $creditor->setUser($user);
        // The DTO Expression invariant guarantees personType/name/address are
        // non-null whenever creditorId is null (manual fill path).
        $creditor->setPersonType($dto->personType);
        $creditor->setName($dto->name);
        $creditor->setAddress($dto->address);
        $creditor->setAddressCounty($dto->addressCounty);
        $creditor->setAddressLocality($dto->addressLocality);
        $creditor->setAnafCheckedAt($this->parseAnafCheckedAt($dto->anafCheckedAt));
        $creditor->setCui($dto->cui);
        $creditor->setPersonalId($dto->personalId);
        $creditor->setOnrcNumber($dto->onrcNumber);
        $creditor->setIban($dto->iban);
        $creditor->setBankName($dto->bankName);
        $creditor->setLegalRepresentative($dto->legalRepresentative);

        $this->em->persist($creditor);

        return new CreditorResolution($creditor, false, 'new');
    }

    /**
     * The fields both sides fill differently, field to [library, step].
     *
     * @return array<string, array{?string, ?string}>
     */
    private function creditorDifferences(Step1CreditorData $dto, Creditor $company): array
    {
        $library = Step1CreditorData::fromCreditor($company);
        $differs = [];
        foreach (self::CREDITOR_LIBRARY_FIELDS as $field) {
            $mine = trim((string) $dto->{$field});
            $theirs = trim((string) $library->{$field});
            if ($mine !== '' && $theirs !== '' && !$this->comparer->same($field, $mine, $theirs)) {
                $differs[$field] = [$library->{$field}, $dto->{$field}];
            }
        }

        return $differs;
    }

    /**
     * The library's data stands; a field the library leaves empty keeps what
     * this step holds (county and locality only with the same address), since
     * the submission completes the library with it. A creditor a somatie went
     * out under is not completed, so it is taken strictly as the library has it.
     */
    private function takeLibraryCreditor(Step1CreditorData $dto, Creditor $company, bool $strict = false): void
    {
        $library = Step1CreditorData::fromCreditor($company);
        $sameAddress = trim((string) $dto->address) === trim((string) $library->address);
        foreach (['personType', 'name', 'cui', 'personalId', ...self::CREDITOR_LIBRARY_FIELDS] as $field) {
            $theirs = $library->{$field};
            $keepsMine = !$strict && ($theirs === null || $theirs === '')
                && ($sameAddress || !in_array($field, ['addressCounty', 'addressLocality'], true));
            if (!$keepsMine) {
                $dto->{$field} = $theirs;
            }
        }
    }

    private function parseAnafCheckedAt(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
