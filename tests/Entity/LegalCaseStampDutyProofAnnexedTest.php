<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\DocumentType;
use App\Enum\StampDutyStatus;
use PHPUnit\Framework\TestCase;

final class LegalCaseStampDutyProofAnnexedTest extends TestCase
{
    public function testPaidWithProofIsAnnexed(): void
    {
        self::assertTrue($this->case(StampDutyStatus::ACHITATA, true)->stampDutyProofAnnexed());
    }

    public function testPaidThroughTheRegistryWithoutProofIsNotAnnexed(): void
    {
        self::assertFalse($this->case(StampDutyStatus::ACHITATA, false)->stampDutyProofAnnexed());
    }

    public function testDeferredStampingIsNotAnnexedEvenWithAProofDocument(): void
    {
        self::assertFalse($this->case(StampDutyStatus::AMANATA_REGULARIZARE, true)->stampDutyProofAnnexed());
    }

    private function case(StampDutyStatus $status, bool $withProof): LegalCase
    {
        $case = new LegalCase();
        $case->setStampDutyStatus($status);
        if ($withProof) {
            $proof = new Document();
            $proof->setDocumentType(DocumentType::DOVADA_TAXA_TIMBRU);
            $case->addDocument($proof);
        }

        return $case;
    }
}
