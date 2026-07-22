<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\Entity\Document;
use App\Enum\ConflictScope;
use App\Enum\DocumentType;
use App\Repository\DocumentRepository;
use App\Service\Extraction\PrefillFromExtractionService;
use PHPUnit\Framework\TestCase;

/**
 * Invoices to different debtors are different claims.
 *
 * The wizard assembles one case out of every document it was given, and the
 * totals add every position. Two invoices to two unrelated companies added
 * into one payment order state a debt nobody owes: unless the debtors answer
 * for one another (CPC art. 59), these are separate cases with separate stamp
 * duty, and only the lawyer can tell which it is.
 */
final class ClaimsAcrossDebtorsTest extends TestCase
{
    public function testInvoicesToTwoDebtorsBlockTheStep(): void
    {
        $result = $this->serviceFor([
            $this->invoiceTo(1, 'Alfa SRL', '11111111', 1000.0),
            $this->invoiceTo(2, 'Beta SRL', '22222222', 2000.0),
        ])->aggregate([1, 2]);

        $blocking = $result->blockingConflicts();
        self::assertNotSame([], $blocking);
        self::assertSame(ConflictScope::DEBTOR_SET, $blocking[0]->scope);
        self::assertSame('wizard.conflict.debtor_set.claims_span_debtors', $blocking[0]->messageKey);
    }

    public function testTwoInvoicesToOneDebtorDoNotBlockAnything(): void
    {
        $result = $this->serviceFor([
            $this->invoiceTo(1, 'Alfa SRL', '11111111', 1000.0),
            $this->invoiceTo(2, 'S.C. ALFA S.R.L.', 'RO 11111111', 2000.0),
        ])->aggregate([1, 2]);

        self::assertFalse($result->hasBlockingConflicts());
    }

    public function testASecondDebtorWithoutAClaimOfItsOwnOnlyInforms(): void
    {
        // A guarantor named in a contract is a second party, not a second
        // claim: nothing is being added up, so nothing has to be stopped.
        $result = $this->serviceFor([
            $this->invoiceTo(1, 'Alfa SRL', '11111111', 1000.0),
            $this->document(2, DocumentType::CONTRACT, ['personType' => 'PJ', 'name' => 'Beta SRL', 'cui' => '22222222'], null),
        ])->aggregate([1, 2]);

        self::assertFalse($result->hasBlockingConflicts());
    }

    /**
     * @param list<Document> $documents
     */
    private function serviceFor(array $documents): PrefillFromExtractionService
    {
        $repository = $this->createStub(DocumentRepository::class);
        $repository->method('findBy')->willReturn($documents);

        return new PrefillFromExtractionService($repository);
    }

    private function invoiceTo(int $id, string $name, string $cui, float $amount): Document
    {
        return $this->document(
            $id,
            DocumentType::FACTURA,
            ['personType' => 'PJ', 'name' => $name, 'cui' => $cui],
            ['amount' => $amount, 'currency' => 'RON', 'confidencePerField' => ['amount' => 0.95, 'currency' => 0.95]],
        );
    }

    /**
     * @param array<string, mixed> $debtor
     * @param ?array<string, mixed> $claim
     */
    private function document(int $id, DocumentType $type, array $debtor, ?array $claim): Document
    {
        $confidence = [];
        foreach ($debtor as $field => $_) {
            $confidence[$field] = 0.95;
        }
        $payload = [
            'schemaVersion' => 2,
            'sourceDocumentId' => $id,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'debtors' => [$debtor + ['confidencePerField' => $confidence]],
        ];
        if ($claim !== null) {
            $payload['claim'] = $claim;
        }

        $document = new Document();
        $document->setDocumentType($type);
        $document->setExtractedData($payload);
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);

        return $document;
    }
}
