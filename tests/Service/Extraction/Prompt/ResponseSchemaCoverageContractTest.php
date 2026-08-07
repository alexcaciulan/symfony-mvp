<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction\Prompt;

use App\DTO\Wizard\Step3ClaimData;
use App\Enum\DocumentType;
use App\Service\Extraction\CoverageConfidenceCalculator;
use App\Service\Extraction\Prompt\SharedPromptFragments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The response schema and the coverage formula are two halves of one contract
 * that nothing enforces at runtime.
 *
 * Constrained decoding requires a closed confidence object, so a field absent
 * from `confidencePerField` can never be scored, and a field that is never
 * scored is dropped at prefill and counts as nothing toward coverage. The
 * failure is silent in both directions: the extraction looks poor, the lawyer
 * refills a field the model actually read.
 */
final class ResponseSchemaCoverageContractTest extends TestCase
{
    /**
     * @param 'creditor'|'debtor'|'claim' $section
     */
    #[DataProvider('coreFieldSections')]
    public function testEveryFieldThatCountsTowardCoverageCanBeScored(string $section, string $constant): void
    {
        $scoreable = $this->confidenceKeys($section);

        /** @var list<string> $coreFields */
        $coreFields = (new \ReflectionClass(CoverageConfidenceCalculator::class))->getConstant($constant);
        foreach ($coreFields as $field) {
            self::assertContains(
                $field,
                $scoreable,
                sprintf('%s.%s counts toward coverage but the model cannot score it', $section, $field),
            );
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function coreFieldSections(): iterable
    {
        yield 'creditor' => ['creditor', 'CORE_CREDITOR_FIELDS'];
        yield 'debtor' => ['debtor', 'CORE_DEBTOR_FIELDS'];
        yield 'claim' => ['claim', 'CORE_CLAIM_FIELDS'];
    }

    #[DataProvider('typesWithNarrowerExpectations')]
    public function testEveryFieldATypeIsExpectedToCarryCanBeScored(DocumentType $type): void
    {
        $expected = $this->expectedFieldsFor($type);
        self::assertNotNull($expected, $type->value . ' is expected to have its own field set');

        foreach (['creditor', 'debtor', 'claim'] as $index => $section) {
            $scoreable = $this->confidenceKeys($section);
            foreach ($expected[$index] as $field) {
                self::assertContains(
                    $field,
                    $scoreable,
                    sprintf('%s expects %s.%s, which the model has no way to score', $type->value, $section, $field),
                );
            }
        }
    }

    /**
     * @return iterable<string, array{DocumentType}>
     */
    public static function typesWithNarrowerExpectations(): iterable
    {
        foreach ([
            DocumentType::FACTURA,
            DocumentType::CONTRACT,
            DocumentType::ACT_ADITIONAL,
            DocumentType::EXTRAS_CONT,
            DocumentType::CONFIRMARE_SOLD,
            DocumentType::SOMATIE_ANTERIOARA,
            DocumentType::NOTIFICARE,
            DocumentType::PROCES_VERBAL,
            DocumentType::COMANDA,
            DocumentType::TITLU_VALOARE,
        ] as $type) {
            yield $type->value => [$type];
        }
    }

    public function testAValueTheModelMayReturnAlwaysHasAMatchingScoreSlot(): void
    {
        // The reverse direction: a value the schema allows but no score can be
        // attached to is a field that reaches the DTO and is then dropped by
        // the prefill threshold, so it looks like the model never read it.
        foreach (['creditor', 'debtor', 'claim'] as $section) {
            $properties = $this->sectionProperties($section);
            unset($properties['confidencePerField']);
            self::assertSame(
                array_keys($properties),
                $this->confidenceKeys($section),
                $section . ': every returnable field needs a score slot, and no slot may name a field that does not exist',
            );
        }
    }

    public function testTheTypesOfferedToTheClassifierAreTheOnesTheWizardCanCorrectTo(): void
    {
        // A type the model may return but the correction form refuses would be
        // a state the lawyer cannot undo: the document sits on a type they
        // cannot re-select.
        $offered = SharedPromptFragments::responseSchema(withClassification: true)
            ['properties']['classification']['properties']['type']['enum'];

        self::assertSame(
            array_map(static fn (DocumentType $type): string => $type->value, SharedPromptFragments::classifiableTypes()),
            $offered,
        );

        // Narrower than the upload list is fine, wider is not: anything the model may
        // return has to be a type the lawyer can also re-select by hand.
        self::assertEmpty(
            array_diff(
                array_map(static fn (DocumentType $type): string => $type->value, SharedPromptFragments::classifiableTypes()),
                array_map(static fn (DocumentType $type): string => $type->value, DocumentType::uploadableTypes()),
            ),
            'The classifier must not be offered a type the correction form refuses.',
        );
    }

    public function testEveryOfferedTypeIsDescribedInTheSystemBlock(): void
    {
        // An enum value with no description is a value the model picks by name
        // alone, which is how a "dovada" ends up meaning whatever the model
        // assumes it means.
        $system = SharedPromptFragments::systemPrompt();
        foreach (SharedPromptFragments::classifiableTypes() as $type) {
            self::assertMatchesRegularExpression(
                '/^\s*• ' . preg_quote($type->value, '/') . ': \S+/m',
                $system,
                $type->value . ' is offered to the classifier without a description',
            );
        }
    }

    // ---------- the value formats the readers accept ----------

    /**
     * @param 'creditor'|'debtor'|'claim' $section
     */
    #[DataProvider('dateFields')]
    public function testEveryDateFieldPrescribesTheFormatTheReaderAccepts(string $section, string $field): void
    {
        // The reader parses `Y-m-d` strictly and drops anything else, while a
        // Romanian document writes 15.03.2025. A due date lost this way is
        // lost silently, and it is the field the interest is computed from.
        $property = $this->sectionProperties($section)[$field];

        self::assertSame('date', $property['format'], $field . ' does not declare a date format');
        self::assertStringContainsString('YYYY-MM-DD', $property['description']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dateFields(): iterable
    {
        yield 'dueDate' => ['claim', 'dueDate'];
        yield 'invoiceDate' => ['claim', 'invoiceDate'];
        yield 'contractDate' => ['claim', 'contractDate'];
    }

    public function testTheDateFormatIsAlsoStatedInProseForAModelThatNeverSeesTheSchema(): void
    {
        self::assertStringContainsString('YYYY-MM-DD', SharedPromptFragments::systemPrompt());
    }

    public function testCurrencyOffersOnlyWhatTheClaimFormAccepts(): void
    {
        // A prefilled `USD` fails validation on a field the lawyer believes was
        // filled in for them.
        self::assertSame(
            Step3ClaimData::SUPPORTED_CURRENCIES,
            $this->sectionProperties('claim')['currency']['enum'],
        );
    }

    /**
     * @param 'creditor'|'debtor'|'claim' $section
     */
    #[DataProvider('formatSensitiveFields')]
    public function testFieldsWithACoercedFormatSayWhatThatFormatIs(string $section, string $field, string $expected): void
    {
        // Each of these is normalised or validated on the way in, so a value in
        // the wrong shape is discarded rather than corrected.
        self::assertStringContainsString($expected, $this->sectionProperties($section)[$field]['description']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function formatSensitiveFields(): iterable
    {
        yield 'cui without the RO prefix' => ['creditor', 'cui', 'RO'];
        yield 'personalId is a CNP' => ['debtor', 'personalId', '13'];
        yield 'onrcNumber canonical form' => ['creditor', 'onrcNumber', 'J40/1234/2025'];
        yield 'iban without spaces' => ['debtor', 'iban', 'RO'];
        yield 'phone in compact form' => ['debtor', 'phone', '0XXXXXXXXX'];
        yield 'invoiceNumber carries the series' => ['claim', 'invoiceNumber', 'MJ 2024-00123'];
    }

    public function testAProformaIsNotOfferedAsAFiscalInvoice(): void
    {
        // A proforma is not a fiscal document and does not on its own found a
        // certain, liquid and due claim, so it must not be classified as the
        // document the amount is read from as authoritative.
        $system = SharedPromptFragments::systemPrompt();

        self::assertMatchesRegularExpression('/^\s*• factura: .*NU proformă/m', $system);
    }

    /**
     * The keys the model may put a score under, for one section.
     *
     * @return list<string>
     */
    private function confidenceKeys(string $section): array
    {
        return array_keys($this->sectionProperties($section)['confidencePerField']['properties']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionProperties(string $section): array
    {
        return SharedPromptFragments::responseSchema(withClassification: false)
            ['properties'][$section]['properties'];
    }

    /**
     * @return array{list<string>, list<string>, list<string>}|null
     */
    private function expectedFieldsFor(DocumentType $type): ?array
    {
        $method = new \ReflectionMethod(CoverageConfidenceCalculator::class, 'expectedFieldsFor');

        return $method->invoke(null, $type);
    }
}
