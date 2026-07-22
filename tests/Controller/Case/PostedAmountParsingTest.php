<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Controller\Case\CaseWizardController;
use PHPUnit\Framework\TestCase;

/**
 * The per-position amount a lawyer types into the step 3 table becomes the
 * principal filed with the court, so its parsing has to reject everything that
 * is not a plain positive number, keep the extracted value on a blank, and read
 * both Romanian (comma decimal, dotted thousands) and plain notation.
 */
final class PostedAmountParsingTest extends TestCase
{
    private \ReflectionMethod $parse;
    private CaseWizardController $controller;

    protected function setUp(): void
    {
        // postedAmount() does not touch $this, so an instance without the
        // constructor's dependencies is enough to exercise it.
        $this->controller = (new \ReflectionClass(CaseWizardController::class))->newInstanceWithoutConstructor();
        $this->parse = new \ReflectionMethod(CaseWizardController::class, 'postedAmount');
    }

    private function parse(mixed $raw): ?float
    {
        return $this->parse->invoke($this->controller, $raw);
    }

    public function testScientificNotationIsRejected(): void
    {
        // is_numeric('1e6') is true; without a character guard this becomes a
        // million and silently corrupts the claim.
        self::assertNull($this->parse('1e6'));
        self::assertNull($this->parse('1E6'));
    }

    public function testRomanianDecimalCommaIsParsed(): void
    {
        self::assertSame(600.50, $this->parse('600,50'));
    }

    public function testDottedThousandsWithCommaDecimalIsParsed(): void
    {
        self::assertSame(1234.56, $this->parse('1.234,56'));
    }

    public function testPlainDotDecimalIsParsed(): void
    {
        self::assertSame(493.23, $this->parse('493.23'));
    }

    public function testBlankKeepsTheExtractedValue(): void
    {
        self::assertNull($this->parse(''));
        self::assertNull($this->parse('   '));
    }

    public function testZeroAndNegativeAreRejected(): void
    {
        self::assertNull($this->parse('0'));
        self::assertNull($this->parse('-100'));
    }

    public function testLettersAndSymbolsAreRejected(): void
    {
        self::assertNull($this->parse('abc'));
        self::assertNull($this->parse('100 RON'));
        self::assertNull($this->parse('12,34,56x'));
    }

    public function testNonStringIsRejected(): void
    {
        self::assertNull($this->parse(null));
        self::assertNull($this->parse(493.23));
        self::assertNull($this->parse(['x']));
    }
}
