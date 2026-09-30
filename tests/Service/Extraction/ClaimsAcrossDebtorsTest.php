<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ConflictResolution;
use App\DTO\Extraction\WizardPrefillResult;
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

        $span = array_values(array_filter(
            $result->blockingConflicts(),
            static fn ($c): bool => $c->messageKey === 'wizard.conflict.debtor_set.claims_span_debtors',
        ));
        self::assertCount(1, $span);
        self::assertSame(ConflictScope::DEBTOR_SET, $span[0]->scope);
        // Only removing the other debtor's documents settles it.
        self::assertFalse($span[0]->acknowledgeable);
    }

    public function testTwoInvoicesToOneDebtorDoNotBlockAnything(): void
    {
        $result = $this->serviceFor([
            $this->invoiceTo(1, 'Alfa SRL', '11111111', 1000.0),
            $this->invoiceTo(2, 'S.C. ALFA S.R.L.', 'RO 11111111', 2000.0),
        ])->aggregate([1, 2]);

        self::assertFalse($result->hasBlockingConflicts());
    }

    public function testASecondDebtorWithoutAClaimOfItsOwnIsAChoiceNotABlock(): void
    {
        // A guarantor named in a contract is a second party, not a second
        // claim: nothing is being added up. With one debtor per case the
        // lawyer only has to say which party the case is against.
        $result = $this->serviceFor([
            $this->invoiceTo(1, 'Alfa SRL', '11111111', 1000.0),
            $this->document(2, DocumentType::CONTRACT, ['personType' => 'PJ', 'name' => 'Beta SRL', 'cui' => '22222222'], null),
        ])->aggregate([1, 2]);

        $messages = array_map(static fn ($c): string => $c->messageKey, $result->blockingConflicts());
        self::assertSame(['wizard.conflict.debtor_set.choose'], $messages);
    }

    public function testChoosingTheGuarantorWhileTheInvoiceIsOwedByTheDebtorIsBlocked(): void
    {
        $service = $this->serviceFor([
            $this->invoiceTo(1, 'Alfa SRL', '11111111', 1000.0),
            $this->document(2, DocumentType::CONTRACT, ['personType' => 'PJ', 'name' => 'Beta SRL', 'cui' => '22222222'], null),
        ]);

        $blocking = $this->blockingMessages($service->aggregate([1, 2], $this->choose($service, 1)));

        self::assertContains('wizard.conflict.debtor_set.claims_of_another_party', $blocking);
    }

    public function testOneClaimDocumentNamingJointDebtorsLetsTheLawyerPursueEither(): void
    {
        $document = $this->invoiceTo(1, 'Alfa SRL', '11111111', 1000.0);
        $payload = $document->getExtractedData();
        $payload['debtors'][] = ['personType' => 'PJ', 'name' => 'Beta SRL', 'cui' => '22222222', 'confidencePerField' => ['personType' => 0.95, 'name' => 0.95, 'cui' => 0.95]];
        $document->setExtractedData($payload);
        $service = $this->serviceFor([$document]);

        $blocking = $this->blockingMessages($service->aggregate([1], $this->choose($service, 1)));

        self::assertSame([], $blocking, 'the chosen joint debtor is named on the claim document');
    }

    public function testInvoicesToTheChosenDebtorAndToAnotherAreBlocked(): void
    {
        $service = $this->serviceFor([
            $this->invoiceTo(1, 'Alfa SRL', '11111111', 1000.0),
            $this->invoiceTo(2, 'Beta SRL', '22222222', 2000.0),
        ]);

        $blocking = $this->blockingMessages($service->aggregate([1, 2], $this->choose($service, 0)));

        self::assertContains('wizard.conflict.debtor_set.claims_span_debtors', $blocking);
    }

    /**
     * @return array<string, ConflictResolution>
     */
    private function choose(PrefillFromExtractionService $service, int $index): array
    {
        foreach ($service->aggregate([1, 2])->conflicts as $conflict) {
            if ($conflict->messageKey === PrefillFromExtractionService::DEBTOR_CHOICE_MESSAGE) {
                return [$conflict->key() => new ConflictResolution(
                    conflictKey: $conflict->key(),
                    scope: $conflict->scope,
                    field: $conflict->field,
                    entityKey: $conflict->entityKey,
                    value: $conflict->options[$index]->value,
                    optionIndex: $index,
                    optionSignature: $conflict->options[$index]->displayValue(),
                )];
            }
        }
        self::fail('no choice of party was offered');
    }

    /**
     * @return list<string>
     */
    private function blockingMessages(WizardPrefillResult $result): array
    {
        return array_values(array_filter(
            array_map(static fn ($c): string => $c->messageKey, $result->blockingConflicts()),
            static fn (string $m): bool => $m !== PrefillFromExtractionService::DEBTOR_CHOICE_MESSAGE,
        ));
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
