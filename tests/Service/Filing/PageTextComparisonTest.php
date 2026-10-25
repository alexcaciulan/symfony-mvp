<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Service\Filing\PageTextComparison;
use PHPUnit\Framework\TestCase;

final class PageTextComparisonTest extends TestCase
{
    public function testIdenticalPagesAreExact(): void
    {
        $result = PageTextComparison::compare(['Suma de 200,00 RON', 'Pagina 2'], ['Suma de 200,00 RON', 'Pagina 2']);

        self::assertNotNull($result);
        self::assertTrue($result->exact());
        self::assertSame([], $result->insertedByPage);
        self::assertSame(0, $result->extraPages);
    }

    public function testWordsInsertedAtTheStartMiddleAndEndAreCountedPerPage(): void
    {
        $result = PageTextComparison::compare(
            ['Cerere de ordonanta', 'Pagina doua'],
            ['Semnat Cerere X de ordonanta 2026', 'Pagina doua'],
        );

        self::assertNotNull($result);
        self::assertFalse($result->exact());
        self::assertSame([1 => ['Semnat', 'X', '2026']], $result->insertedByPage);
        self::assertSame(3, $result->insertedWordCount());
    }

    public function testAChangedWordFails(): void
    {
        self::assertNull(PageTextComparison::compare(['Debitor SC Alfa SRL'], ['Debitor SC Beta SRL']));
    }

    public function testAChangedAmountFailsEvenWhenItOnlyGainsADigit(): void
    {
        self::assertNull(PageTextComparison::compare(['Suma 100 lei'], ['Suma 1000 lei']));
        self::assertNull(PageTextComparison::compare(['Suma 1.000 lei'], ['Suma 1000 lei']));
    }

    /** Every generated word has to be consumed: inserted words cannot stand in for a deleted sentence. */
    public function testADeletedSentenceFailsEvenWithWordsAddedOnThePage(): void
    {
        self::assertNull(PageTextComparison::compare(
            ['Prima propozitie. A doua propozitie. A treia.'],
            ['Prima propozitie. A treia. Semnat electronic de avocat la data de azi'],
        ));
    }

    public function testSwappedWordsFail(): void
    {
        self::assertNull(PageTextComparison::compare(['creditor debitor'], ['debitor creditor']));
    }

    public function testFewerSignedPagesFail(): void
    {
        self::assertNull(PageTextComparison::compare(['Unu', 'Doi'], ['Unu']));
    }

    public function testExtraTrailingPagesAreCounted(): void
    {
        $result = PageTextComparison::compare(['Unu', 'Doi'], ['Unu', 'Doi', 'Semnaturi', '']);

        self::assertNotNull($result);
        self::assertSame(2, $result->extraPages);
        self::assertSame([], $result->insertedByPage);
        self::assertFalse($result->exact());
    }

    public function testReversedPagesFail(): void
    {
        self::assertNull(PageTextComparison::compare(['Unu', 'Doi', 'Trei'], ['Trei', 'Doi', 'Unu']));
    }

    /** A page dropped from the middle shifts the following ones out of place. */
    public function testADroppedMiddlePageFailsEvenWithAPageAppended(): void
    {
        self::assertNull(PageTextComparison::compare(['Unu', 'Doi', 'Trei'], ['Unu', 'Trei', 'Semnaturi']));
    }

    public function testATextlessGeneratedPageMatchesOnlyATextlessPage(): void
    {
        self::assertNull(PageTextComparison::compare(['Unu', ''], ['Unu', 'Stampila']));
        self::assertTrue(PageTextComparison::compare(['Unu', ''], ['Unu', ''])?->exact());
        self::assertNull(PageTextComparison::compare(['Unu', 'Doi'], ['Unu', '']));
    }
}
