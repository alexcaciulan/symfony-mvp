<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction\Prompt;

use App\Enum\DocumentType;
use App\Service\Extraction\Prompt\ExtractionPromptInterface;
use App\Service\Extraction\Prompt\GenericDocumentPrompt;
use App\Service\Extraction\Prompt\SharedPromptFragments;
use App\Tests\Support\ExtractionPrompts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Prompt selection and the invariants the whole registry depends on: one
 * system block shared byte for byte (otherwise the provider cache never hits
 * and the party glossary can drift between prompts) and a response schema the
 * provider will accept.
 */
final class ExtractionPromptRegistryTest extends TestCase
{
    public function testAnUndeclaredTypeGetsTheClassifyingPrompt(): void
    {
        $registry = ExtractionPrompts::registry();

        $this->assertSame('generic', $registry->forType(null)->key());
        $this->assertSame('generic', $registry->forType(DocumentType::ALT_DOCUMENT)->key());
    }

    #[DataProvider('specialisedTypes')]
    public function testADeclaredTypeGetsItsOwnPrompt(DocumentType $type, string $expectedKey): void
    {
        $this->assertSame($expectedKey, ExtractionPrompts::registry()->forType($type)->key());
    }

    /**
     * @return iterable<string, array{DocumentType, string}>
     */
    public static function specialisedTypes(): iterable
    {
        yield 'invoice' => [DocumentType::FACTURA, 'invoice'];
        yield 'contract' => [DocumentType::CONTRACT, 'contract'];
        yield 'amendment' => [DocumentType::ACT_ADITIONAL, 'contract'];
        yield 'bank statement' => [DocumentType::EXTRAS_CONT, 'bank_statement'];
        yield 'prior notice' => [DocumentType::SOMATIE_ANTERIOARA, 'prior_notice'];
        yield 'notice' => [DocumentType::NOTIFICARE, 'prior_notice'];
        yield 'acknowledgement' => [DocumentType::CONFIRMARE_SOLD, 'acknowledgement'];
    }

    public function testATypeNobodyClaimsFallsBackToTheGenericPrompt(): void
    {
        $this->assertSame('generic', ExtractionPrompts::registry()->forType(DocumentType::PROCES_VERBAL)->key());
    }

    public function testEveryUploadableTypeResolvesToSomething(): void
    {
        $registry = ExtractionPrompts::registry();
        foreach (DocumentType::uploadableTypes() as $type) {
            $this->assertInstanceOf(ExtractionPromptInterface::class, $registry->forType($type));
        }
    }

    public function testOnlyTheGenericPromptAsksForAClassification(): void
    {
        $registry = ExtractionPrompts::registry();

        $generic = $registry->forType(DocumentType::ALT_DOCUMENT)->outputSchema();
        $invoice = $registry->forType(DocumentType::FACTURA)->outputSchema();

        $this->assertArrayHasKey('classification', $generic['properties']);
        // Asking a specialised prompt to classify would invite the model to
        // contradict a type a human already chose.
        $this->assertArrayNotHasKey('classification', $invoice['properties']);
    }

    public function testTheSystemBlockIsIdenticalAcrossPrompts(): void
    {
        $registry = ExtractionPrompts::registry();
        $reference = $registry->forType(DocumentType::ALT_DOCUMENT)->systemPrompt();

        foreach (DocumentType::uploadableTypes() as $type) {
            // Byte equality, not "roughly the same": the provider serves a
            // cached prefix only on an exact match, so one reworded sentence
            // makes every document in a batch pay full input price.
            $this->assertSame($reference, $registry->forType($type)->systemPrompt());
        }
    }

    public function testTheInstructionsDifferPerType(): void
    {
        $registry = ExtractionPrompts::registry();

        $this->assertNotSame(
            $registry->forType(DocumentType::FACTURA)->userInstructions(),
            $registry->forType(DocumentType::CONTRACT)->userInstructions(),
        );
    }

    public function testOutputBudgetIsSizedPerDocumentKind(): void
    {
        $registry = ExtractionPrompts::registry();

        $this->assertSame(16000, $registry->forType(null)->maxTokens());
        $this->assertSame(16000, $registry->forType(DocumentType::FACTURA)->maxTokens());
        $this->assertSame(16000, $registry->forType(DocumentType::CONFIRMARE_SOLD)->maxTokens());
        $this->assertSame(4096, $registry->forType(DocumentType::CONTRACT)->maxTokens());
        $this->assertSame(4096, $registry->forType(DocumentType::SOMATIE_ANTERIOARA)->maxTokens());
        $this->assertSame(4096, $registry->forType(DocumentType::EXTRAS_CONT)->maxTokens());
    }

    public function testTheSystemBlockCarriesTheRulesTheFilingDependsOn(): void
    {
        $system = (new GenericDocumentPrompt())->systemPrompt();

        $this->assertStringContainsString('Prestator', $system);
        $this->assertStringContainsString('Beneficiar', $system);
        $this->assertStringContainsString('Sector 1', $system);
        $this->assertStringContainsString('CONTRACTUAL', $system);
        $this->assertStringContainsString('confidencePerField', $system);
    }

    public function testTheSchemaHonoursTheConstraintsOfConstrainedDecoding(): void
    {
        $schema = SharedPromptFragments::responseSchema(withClassification: true);

        $this->assertObjectSchemaIsClosed($schema);
    }

    public function testNoFieldIsNullableRatherThanOptional(): void
    {
        // A union counts against a hard provider cap (16 per schema) and this
        // payload has more than forty fields that may legitimately be absent.
        // Absence is expressed by omitting the key instead, which is also what
        // the prompt asks for and what the reader already accepts.
        $this->assertSame(
            [],
            $this->unionProperties(SharedPromptFragments::responseSchema(withClassification: true)),
        );
    }

    public function testOnlyWhatADocumentAlwaysCarriesIsRequired(): void
    {
        // Requiring a field the source does not contain leaves the model no way
        // out but to invent one.
        $schema = SharedPromptFragments::responseSchema(withClassification: true);

        $this->assertArrayNotHasKey('required', $schema);
        $this->assertArrayNotHasKey('required', $schema['properties']['creditor']);
        $this->assertArrayNotHasKey('required', $schema['properties']['claim']);
        $this->assertSame(['type', 'confidence'], $schema['properties']['classification']['required']);
    }

    /**
     * Property paths whose schema is a union (`anyOf` or a list of types).
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function unionProperties(array $schema, string $path = ''): array
    {
        $found = [];
        if (isset($schema['anyOf']) || is_array($schema['type'] ?? null)) {
            $found[] = $path;
        }
        foreach ($schema['properties'] ?? [] as $name => $property) {
            $found = array_merge($found, $this->unionProperties($property, $path . '/' . $name));
        }

        return $found;
    }

    public function testTheClassifierIsOfferedOnlyTypesALawyerCanUpload(): void
    {
        $schema = SharedPromptFragments::responseSchema(withClassification: true);
        $offered = $schema['properties']['classification']['properties']['type']['enum'];

        foreach ($offered as $value) {
            $type = DocumentType::from($value);
            // A generated filing is not something anyone uploads, so offering
            // it would let the model relabel evidence as a court document.
            $this->assertFalse($type->isAutoGenerated(), $value . ' must not be offered to the classifier');
        }
        $this->assertContains(DocumentType::FACTURA->value, $offered);
        $this->assertNotContains(DocumentType::SOMATIE->value, $offered);
        $this->assertContains(DocumentType::SOMATIE_ANTERIOARA->value, $offered);
    }

    /**
     * Constrained decoding rejects an object that allows unknown keys, and it
     * cannot express a map with arbitrary keys.
     *
     * @param array<string, mixed> $schema
     */
    private function assertObjectSchemaIsClosed(array $schema): void
    {
        if (($schema['type'] ?? null) !== 'object') {
            return;
        }

        $this->assertArrayHasKey('additionalProperties', $schema);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertArrayHasKey('properties', $schema);

        foreach ($schema['properties'] as $property) {
            $this->assertObjectSchemaIsClosed($property);
        }
    }
}
