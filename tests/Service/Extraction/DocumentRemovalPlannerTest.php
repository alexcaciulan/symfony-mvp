<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\BnrExchangeRate;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Enum\PersonType;
use App\Enum\RemovalStepOutcome;
use App\Repository\BnrExchangeRateRepository;
use App\Repository\DocumentRepository;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Case\ClaimItemFactory;
use App\Service\Extraction\DocumentRemovalPlanner;
use App\Service\Extraction\PrefillFromExtractionService;
use PHPUnit\Framework\TestCase;

final class DocumentRemovalPlannerTest extends TestCase
{
    public function testAPartyTheDocumentsLeftDoNotNameIsRefilled(): void
    {
        $plan = $this->planner([
            1 => $this->invoice('Cedent SRL', '15193236', 'Debitor SRL', '14186770', 'F-1', 1000.0),
            2 => $this->invoice('Gresit SRL', '33734370', 'Alt Debitor SRL', '18547290', 'G-1', 5000.0),
        ])->plan([
            'documentIds' => [1, 2],
            'creditor' => new Step1CreditorData(personType: PersonType::PJ, name: 'Gresit SRL', cui: 'RO33734370', autoFilled: ['name']),
            'debtors' => new Step2DebtorsData([new Step2DebtorEntry(personType: PersonType::PJ, name: 'Alt Debitor SRL', cui: '18547290', autoFilled: ['name'])]),
        ], 2);

        self::assertSame(RemovalStepOutcome::REFILLED, $plan->creditor);
        self::assertSame('Cedent SRL', $plan->creditorAfter);
        self::assertSame(RemovalStepOutcome::REFILLED, $plan->debtor);
        self::assertSame('Debitor SRL', $plan->debtorAfter);
    }

    public function testTheSamePartyWrittenWithAPrefixIsKept(): void
    {
        $plan = $this->planner([
            1 => $this->invoice('Cedent SRL', '15193236', 'Debitor SRL', '14186770', 'F-1', 1000.0),
            2 => $this->invoice('Cedent SRL', '15193236', 'Debitor SRL', '14186770', 'F-2', 500.0),
        ])->plan([
            'documentIds' => [1, 2],
            'creditor' => new Step1CreditorData(personType: PersonType::PJ, name: 'Cedent SRL', cui: 'RO 15193236', autoFilled: ['name']),
        ], 2);

        self::assertSame(RemovalStepOutcome::KEPT, $plan->creditor);
        self::assertSame(RemovalStepOutcome::NOT_SAVED, $plan->debtor);
    }

    public function testAPartyTypedOrPickedFromTheLibraryIsKept(): void
    {
        // An empty autoFilled list means the lawyer chose the party; the
        // documents never decided it, so removing one does not either.
        $plan = $this->planner([
            1 => $this->invoice('Cedent SRL', '15193236', 'Debitor SRL', '14186770', 'F-1', 1000.0),
            2 => $this->invoice('Gresit SRL', '33734370', 'Debitor SRL', '14186770', 'G-1', 5000.0),
        ])->plan([
            'documentIds' => [1, 2],
            'creditor' => new Step1CreditorData(personType: PersonType::PJ, name: 'Din Biblioteca SRL', cui: '33734370'),
        ], 2);

        self::assertSame(RemovalStepOutcome::KEPT, $plan->creditor);
    }

    public function testNoDocumentLeftRefillsEveryPartyReadFromOne(): void
    {
        $plan = $this->planner([
            1 => $this->invoice('Cedent SRL', '15193236', 'Debitor SRL', '14186770', 'F-1', 1000.0),
        ])->plan([
            'documentIds' => [1],
            'creditor' => new Step1CreditorData(personType: PersonType::PJ, name: 'Cedent SRL', cui: '15193236', autoFilled: ['name']),
        ], 1);

        self::assertSame(RemovalStepOutcome::REFILLED, $plan->creditor);
        self::assertNull($plan->creditorAfter);
        self::assertSame(0, $plan->documentsLeft);
    }

    public function testTheClaimStatesThePrincipalBeforeAndAfter(): void
    {
        $plan = $this->planner([
            1 => $this->invoice('Cedent SRL', '15193236', 'Debitor SRL', '14186770', 'F-1', 1000.0),
            2 => $this->invoice('Cedent SRL', '15193236', 'Debitor SRL', '14186770', 'F-2', 500.0),
        ])->plan([
            'documentIds' => [1, 2],
            'claim' => new Step3ClaimData(amount: 1500.0, legalCostsFixed: 300.0, autoFilled: ['amount']),
            'claimItems' => [
                new ClaimItemRow(dedupKey: 'a', amount: 1000.0, amountRon: 1000.0),
                new ClaimItemRow(dedupKey: 'b', amount: 500.0, amountRon: 500.0),
            ],
        ], 2);

        self::assertTrue($plan->claimRefreshed);
        self::assertTrue($plan->principalChanges());
        self::assertSame(1500.0, $plan->principalBefore);
        self::assertSame(1000.0, $plan->principalAfter);
        self::assertSame(300.0, $plan->claimAfter?->legalCostsFixed, 'the fee is the lawyer\'s');
    }

    public function testAClaimTheRemovalDoesNotTouchIsNotAnnounced(): void
    {
        // Removing a document that states no claim leaves the claim as it was:
        // the dialog must not warn about corrections being lost.
        $plan = $this->planner([
            1 => $this->invoice('Cedent SRL', '15193236', 'Debitor SRL', '14186770', 'F-1', 1000.0),
            2 => $this->partiesOnly('Cedent SRL', '15193236'),
        ])->plan([
            'documentIds' => [1, 2],
            'claim' => $this->prefillOf([1 => $this->invoice('Cedent SRL', '15193236', 'Debitor SRL', '14186770', 'F-1', 1000.0)]),
        ], 2);

        self::assertFalse($plan->claimRefreshed);
        self::assertFalse($plan->principalChanges());
    }

    /**
     * @param array<int, Document> $documents
     */
    private function planner(array $documents): DocumentRemovalPlanner
    {
        foreach ($documents as $id => $document) {
            (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);
        }
        $repository = $this->createStub(DocumentRepository::class);
        $repository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_values(array_filter(
                $documents,
                static fn (Document $d): bool => in_array($d->getId(), (array) $criteria['id'], true),
            )),
        );
        $rates = $this->createStub(BnrExchangeRateRepository::class);
        $rates->method('findRateValidAt')->willReturn(new BnrExchangeRate());

        return new DocumentRemovalPlanner(
            new PrefillFromExtractionService($repository),
            new ClaimItemFactory($repository, new CurrencyConverter($rates)),
        );
    }

    /**
     * The claim step as the prefill saved it from these documents.
     *
     * @param array<int, Document> $documents
     */
    private function prefillOf(array $documents): Step3ClaimData
    {
        foreach ($documents as $id => $document) {
            (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);
        }
        $repository = $this->createStub(DocumentRepository::class);
        $repository->method('findBy')->willReturn(array_values($documents));

        return (new PrefillFromExtractionService($repository))->aggregateForClaim(array_keys($documents));
    }

    private function invoice(string $creditor, string $creditorCui, string $debtor, string $debtorCui, string $number, float $amount): Document
    {
        return $this->document([
            'creditor' => ['personType' => 'PJ', 'name' => $creditor, 'cui' => $creditorCui, 'confidencePerField' => ['personType' => 0.95, 'name' => 0.95, 'cui' => 0.95]],
            'debtors' => [['personType' => 'PJ', 'name' => $debtor, 'cui' => $debtorCui, 'confidencePerField' => ['personType' => 0.95, 'name' => 0.95, 'cui' => 0.95]]],
            'claim' => [
                'amount' => $amount, 'currency' => 'RON', 'invoiceNumber' => $number,
                'dueDate' => '2025-01-31T00:00:00+00:00', 'invoiceDate' => '2025-01-01T00:00:00+00:00',
                'confidencePerField' => ['amount' => 0.95, 'currency' => 0.95, 'invoiceNumber' => 0.95, 'dueDate' => 0.95, 'invoiceDate' => 0.95],
            ],
        ]);
    }

    private function partiesOnly(string $creditor, string $creditorCui): Document
    {
        return $this->document([
            'creditor' => ['personType' => 'PJ', 'name' => $creditor, 'cui' => $creditorCui, 'confidencePerField' => ['personType' => 0.95, 'name' => 0.95, 'cui' => 0.95]],
        ], DocumentType::CONTRACT);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function document(array $payload, DocumentType $type = DocumentType::FACTURA): Document
    {
        $document = new Document();
        $document->setDocumentType($type);
        $document->setExtractedData(['schemaVersion' => 2, 'strategy' => 'ai_vision', 'globalConfidence' => 0.9, ...$payload]);

        return $document;
    }
}
