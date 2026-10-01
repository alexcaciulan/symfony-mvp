<?php

declare(strict_types=1);

namespace App\Tests\Service\Party;

use App\Service\Party\CuiNormalizer;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The migration computes the CUI key in SQL for the rows that existed, the
 * application in PHP for every row after. Two answers for one company would
 * split it in two, so both have to agree on every spelling.
 */
final class CuiNormalizerSqlParityTest extends KernelTestCase
{
    private const SQL = "SELECT NULLIF(TRIM(LEADING '0' FROM CASE WHEN UPPER(REGEXP_REPLACE(d.cui, '[[:space:]]', '')) LIKE 'RO%' THEN SUBSTRING(UPPER(REGEXP_REPLACE(d.cui, '[[:space:]]', '')), 3) ELSE UPPER(REGEXP_REPLACE(d.cui, '[[:space:]]', '')) END), '') FROM (SELECT ? AS cui) d";

    public function testTheMigrationAndTheApplicationAgreeOnEverySpelling(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        foreach (['RO123', 'ro 123', 'R O123', '0123', 'RO0123', '123 ', "RO\u{00A0}123", "12\t3", 'RO', '0', ''] as $spelling) {
            self::assertSame(
                CuiNormalizer::canonical($spelling),
                $connection->fetchOne(self::SQL, [$spelling]) ?? null,
                sprintf('"%s" must get the same key in PHP and in SQL', $spelling),
            );
        }
    }

    public function testTheCreditorMigrationUsesTheSameExpressionAsTheDebtorOne(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/Version20260930150000.php';
        require_once dirname(__DIR__, 3) . '/migrations/Version20261001120000.php';
        $debtor = (new \ReflectionClassConstant(\DoctrineMigrations\Version20260930150000::class, 'CUI_KEY_SQL'))->getValue();
        $creditor = (new \ReflectionClassConstant(\DoctrineMigrations\Version20261001120000::class, 'CUI_KEY_SQL'))->getValue();

        self::assertSame(str_replace('d.cui', 'c.cui', $debtor), $creditor);
    }
}
