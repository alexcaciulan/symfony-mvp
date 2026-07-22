<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ConflictOption;
use App\DTO\Extraction\PrefillConflict;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Enum\DocumentType;
use App\Service\Extraction\ConflictResolutionService;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial pass over the value the lawyer types instead of choosing a
 * document.
 *
 * It is the only value in the file no document backs, so nothing downstream can
 * catch it being wrong: it goes into the sum claimed, and the sum claimed is
 * what the court is asked to order paid. Reading it loosely is worse than
 * refusing it, because a refusal is visible and a misread sum is not.
 */
final class ConflictResolutionTypedValueAdversarialTest extends TestCase
{
    private ConflictResolutionService $service;

    protected function setUp(): void
    {
        $this->service = new ConflictResolutionService();
    }

    /**
     * A sum is written "1.500" in Romanian and means one thousand five hundred.
     * Read as a decimal point it becomes one leu and a half, and the petition
     * claims a thousandth of the debt without anything saying so.
     */
    public function testASumTypedWithARomanianThousandsSeparatorIsNotDividedByAThousand(): void
    {
        $conflict = $this->amountConflict();

        $resolved = $this->service->collect(
            [$conflict->key() => ConflictResolutionService::MANUAL_CHOICE],
            [$conflict->key() => '1.500'],
            [$conflict],
            [],
        );

        $resolution = $resolved[$conflict->key()] ?? null;
        self::assertNotNull($resolution);
        self::assertNotSame(1.5, $resolution->value, 'A thousands separator cannot be read as a decimal point');
        self::assertSame(1500.0, $resolution->value);
    }

    /**
     * The full Romanian form, thousands and decimals both. Either it is read
     * correctly or it is refused, but it must not land on some other number.
     */
    public function testASumTypedInFullRomanianFormIsEitherReadOrRefused(): void
    {
        $conflict = $this->amountConflict();

        $resolved = $this->service->collect(
            [$conflict->key() => ConflictResolutionService::MANUAL_CHOICE],
            [$conflict->key() => '1.500,50'],
            [$conflict],
            [],
        );

        $resolution = $resolved[$conflict->key()] ?? null;
        if ($resolution === null) {
            // Refused: the conflict stays open and the lawyer is asked again.
            self::assertArrayNotHasKey($conflict->key(), $resolved);

            return;
        }
        self::assertSame(1500.5, $resolution->value);
    }

    /**
     * A sum has to be a sum. Letters in the field are a typing accident, and
     * taking the leading digits of them would claim a number nobody wrote.
     */
    public function testAnUnreadableSumLeavesTheConflictOpen(): void
    {
        $conflict = $this->amountConflict();

        $resolved = $this->service->collect(
            [$conflict->key() => ConflictResolutionService::MANUAL_CHOICE],
            [$conflict->key() => '1200 lei'],
            [$conflict],
            [],
        );

        self::assertArrayNotHasKey($conflict->key(), $resolved);
        self::assertSame([$conflict], $this->service->pendingChoices([$conflict], $resolved));
    }

    /**
     * Zero is arithmetically valid and legally meaningless: a payment order for
     * nothing. The lawyer typing it is a slip, and the step is the last place
     * to notice.
     */
    public function testASumThatIsNotAPositiveAmountIsNotRetainedAsTheClaim(): void
    {
        foreach (['0', '-500'] as $typed) {
            $conflict = $this->amountConflict();

            $resolved = $this->service->collect(
                [$conflict->key() => ConflictResolutionService::MANUAL_CHOICE],
                [$conflict->key() => $typed],
                [$conflict],
                [],
            );

            self::assertArrayNotHasKey(
                $conflict->key(),
                $resolved,
                sprintf('"%s" is not a sum a payment order can be issued for', $typed),
            );
        }
    }

    private function amountConflict(): PrefillConflict
    {
        return new PrefillConflict(
            scope: ConflictScope::CLAIM,
            severity: ConflictSeverity::ERROR,
            messageKey: 'wizard.conflict.field.amount',
            field: 'amount',
            options: [
                new ConflictOption(value: 1000.0, documentId: 1, documentType: DocumentType::FACTURA, confidence: 0.9),
                new ConflictOption(value: 1200.0, documentId: 2, documentType: DocumentType::FACTURA, confidence: 0.9),
            ],
            suggestedIndex: 0,
        );
    }
}
