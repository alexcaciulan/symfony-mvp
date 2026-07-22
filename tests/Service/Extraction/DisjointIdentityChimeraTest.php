<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\FieldSource;
use App\Entity\Document;
use App\Enum\ConflictScope;
use App\Enum\DocumentType;
use App\Enum\FieldGroup;
use App\Repository\DocumentRepository;
use App\Service\Extraction\CoherentAggregator;
use App\Service\Extraction\PrefillFromExtractionService;
use PHPUnit\Framework\TestCase;

/**
 * The chimera the group guard did not catch: two documents that never
 * contradict each other, because each says what the other is silent about.
 *
 * A contract names the debtor and carries no registration number, an invoice to
 * a sister company carries the number. Nothing contradicts, so the pair used to
 * be declared one party and the filing named a company that does not exist: a
 * real name against another taxpayer's number. Silence is not agreement.
 */
final class DisjointIdentityChimeraTest extends TestCase
{
    public function testANumberFromAnotherDocumentDoesNotCompleteANameItNeverStated(): void
    {
        $result = $this->aggregate([
            $this->source(1, DocumentType::CONTRACT, ['name' => 'ALFA TRANS SRL']),
            $this->source(2, DocumentType::FACTURA, ['cui' => '222', 'address' => 'Cluj-Napoca']),
        ]);

        self::assertSame('ALFA TRANS SRL', $result->values['name']);
        self::assertArrayNotHasKey('cui', $result->values);
    }

    public function testTheLegalFormSeparatesTwoCompaniesOfOneGroup(): void
    {
        $result = $this->aggregate([
            $this->source(1, DocumentType::CONTRACT, ['name' => 'ALFA TRANS SRL']),
            $this->source(2, DocumentType::FACTURA, ['name' => 'ALFA TRANS SA', 'cui' => '222']),
        ]);

        self::assertSame('ALFA TRANS SRL', $result->values['name']);
        self::assertArrayNotHasKey('cui', $result->values);
    }

    public function testACompanyNumberAndAPersonalNumberAreNeverOneParty(): void
    {
        $aggregator = new CoherentAggregator();
        $company = $this->source(1, DocumentType::CONTRACT, ['name' => 'ION POPESCU', 'cui' => '111']);
        $person = $this->source(2, DocumentType::FACTURA, ['name' => 'ION POPESCU', 'personalId' => '1900101223344']);

        self::assertFalse($aggregator->provesSameParty($company, $person));
        self::assertFalse($aggregator->isSameIdentity($company, $person));
    }

    public function testAnIdentifierThatCouldNotBeTiedToThePartyIsReported(): void
    {
        $result = $this->aggregate([
            $this->source(1, DocumentType::CONTRACT, ['name' => 'ALFA TRANS SRL']),
            $this->source(2, DocumentType::FACTURA, ['cui' => '222']),
        ]);

        $keys = array_map(static fn ($c) => $c->messageKey, $result->conflicts);
        self::assertContains('wizard.conflict.party.identity_unmatched', $keys);
    }

    public function testTheSameCompanyNamedTwiceStillGainsItsNumberAndSaysSo(): void
    {
        $result = $this->aggregate([
            $this->source(1, DocumentType::CONTRACT, ['name' => 'ALFA TRANS SRL']),
            $this->source(2, DocumentType::FACTURA, ['name' => 'S.C. ALFA TRANS S.R.L.', 'cui' => '222']),
        ]);

        self::assertSame('222', $result->values['cui']);
        $keys = array_map(static fn ($c) => $c->messageKey, $result->conflicts);
        self::assertContains('wizard.conflict.party.identity_completed', $keys);
    }

    public function testAClaimFigureStillTravelsBetweenDocumentsThatIdentifyNobody(): void
    {
        // The party guard has no business over the claim: a currency read off a
        // second document cannot produce a person who does not exist, and
        // demanding proof of identity there would stop ordinary prefilling.
        $aggregator = new CoherentAggregator();
        $fields = [];
        foreach (['amount', 'currency', 'dueDate'] as $field) {
            $fields[$field] = FieldGroup::claimFieldMap()[$field];
        }

        $result = $aggregator->aggregate([
            new FieldSource(1, DocumentType::FACTURA, ['amount' => 1000.0], ['amount' => 0.95]),
            new FieldSource(2, DocumentType::CONTRACT, ['currency' => 'EUR'], ['currency' => 0.95]),
        ], $fields, ConflictScope::CLAIM);

        self::assertSame('EUR', $result->values['currency']);
    }

    public function testTheDebtorCardTakesNoNumberFromASisterCompanyEndToEnd(): void
    {
        $documents = [
            $this->document(1, DocumentType::CONTRACT, ['personType' => 'PJ', 'name' => 'ALFA TRANS SRL']),
            $this->document(2, DocumentType::FACTURA, ['personType' => 'PJ', 'name' => 'ALFA TRANS SA', 'cui' => '222']),
        ];
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findBy')->willReturn($documents);

        $debtors = (new PrefillFromExtractionService($repo))->aggregateForDebtors([1, 2])->debtors;

        foreach ($debtors as $debtor) {
            self::assertNotSame(
                ['ALFA TRANS SRL', '222'],
                [$debtor->name, $debtor->cui],
                'the name of one company must never be paired with another company\'s registration number',
            );
        }
    }

    /**
     * @param list<FieldSource> $sources
     */
    private function aggregate(array $sources): \App\DTO\Extraction\AggregatedFields
    {
        $fields = [];
        foreach (['personType', 'name', 'cui', 'personalId', 'address', 'county', 'email'] as $field) {
            $fields[$field] = FieldGroup::partyFieldMap()[$field];
        }

        return (new CoherentAggregator())->aggregate($sources, $fields, ConflictScope::DEBTOR);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function source(int $id, DocumentType $type, array $values): FieldSource
    {
        $confidence = [];
        foreach ($values as $field => $_) {
            $confidence[$field] = 0.95;
        }

        return new FieldSource($id, $type, $values, $confidence);
    }

    /**
     * @param array<string, mixed> $debtor
     */
    private function document(int $id, DocumentType $type, array $debtor): Document
    {
        $confidence = [];
        foreach ($debtor as $field => $_) {
            $confidence[$field] = 0.95;
        }
        $document = new Document();
        $document->setDocumentType($type);
        $document->setExtractedData([
            'schemaVersion' => 2,
            'sourceDocumentId' => $id,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'debtors' => [$debtor + ['confidencePerField' => $confidence]],
        ]);
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);

        return $document;
    }
}
