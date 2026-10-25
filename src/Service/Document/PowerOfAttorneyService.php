<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\DTO\Document\PowerOfAttorneyDetails;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Service\AuditLogService;
use App\Service\Filing\FilingLockQuery;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Series, number and date of the power of attorney uploaded on a case, and the
 * one comparison of that date with the summons that upload, edit and the
 * generation dialog all share.
 */
final class PowerOfAttorneyService
{
    /**
     * Statuses in which the details may still change. Once the petition is filed,
     * the index the court holds lists the act as it was; correcting it here would
     * leave the platform describing a document the court never saw. Until then the
     * index is rebuilt from these values at every package download.
     */
    private const EDITABLE_STATUSES = [
        CaseStatus::AMIABIL,
        CaseStatus::SOMATIE_TRIMISA,
        CaseStatus::CERERE_GENERATA,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogService $auditLogService,
        private readonly FilingLockQuery $filingLock,
    ) {}

    public function apply(Document $document, PowerOfAttorneyDetails $details): void
    {
        $this->assertPowerOfAttorney($document);

        $document->setPowerOfAttorneySeries($details->series);
        $document->setPowerOfAttorneyNumber($details->number);
        $document->setPowerOfAttorneyDate($details->date);
    }

    public function updateDetails(Document $document, PowerOfAttorneyDetails $details): void
    {
        $this->assertPowerOfAttorney($document);

        $oldData = self::snapshot($document);
        $this->apply($document, $details);
        $this->em->flush();

        $this->auditLogService->log(
            action: 'document_power_of_attorney_update',
            entityType: 'Document',
            entityId: (string) $document->getId(),
            oldData: $oldData,
            newData: self::snapshot($document),
        );
        $this->em->flush();
    }

    public function canEditDetails(LegalCase $case): bool
    {
        // The act travels in the package being sent; its details must match the signed file.
        return $case->getFullPaymentDate() === null
            && in_array($case->getStatus(), self::EDITABLE_STATUSES, true)
            && !$this->filingLock->isLocked($case);
    }

    /**
     * Whether the act is dated after the day the platform generated the summons.
     * Compared by calendar day, so an act dated the same day never warns. The
     * summons date is the generation date: the platform does not know when the
     * summons was signed or sent, and the debtor's receipt date is irrelevant here.
     * Computed on read so a regenerated summons moves the result with it.
     */
    public function dateIsAfterSummons(Document $document, LegalCase $case): bool
    {
        $date = $document->getPowerOfAttorneyDate();
        $summonsDate = $case->getPaymentNoticeDate();
        if ($date === null || $summonsDate === null) {
            return false;
        }

        return $date->format('Y-m-d') > $summonsDate->format('Y-m-d');
    }

    /**
     * @return array{powerOfAttorneySeries: ?string, powerOfAttorneyNumber: ?string, powerOfAttorneyDate: ?string}
     */
    public static function snapshot(Document $document): array
    {
        return [
            'powerOfAttorneySeries' => $document->getPowerOfAttorneySeries(),
            'powerOfAttorneyNumber' => $document->getPowerOfAttorneyNumber(),
            'powerOfAttorneyDate' => $document->getPowerOfAttorneyDate()?->format('Y-m-d'),
        ];
    }

    private function assertPowerOfAttorney(Document $document): void
    {
        if ($document->getDocumentType() !== DocumentType::IMPUTERNICIRE_AVOCATIALA) {
            throw new \LogicException(sprintf('Document %s is not a power of attorney.', (string) $document->getId()));
        }
    }
}
