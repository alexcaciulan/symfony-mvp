<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creditors are told apart by the canonical CUI, as debtors are: "RO15193236"
 * and "15193236" are one company. The key is computed in SQL for the rows that
 * exist (same expression as Version20260930150000, kept equal to CuiNormalizer
 * by CuiNormalizerSqlParityTest), and the uniqueness moves from the typed CUI
 * to the key. A library holding two spellings of one CUI must be merged first.
 */
final class Version20261001120000 extends AbstractMigration
{
    private const CUI_KEY_SQL = "NULLIF(TRIM(LEADING '0' FROM CASE WHEN UPPER(REGEXP_REPLACE(c.cui, '[[:space:]]', '')) LIKE 'RO%' THEN SUBSTRING(UPPER(REGEXP_REPLACE(c.cui, '[[:space:]]', '')), 3) ELSE UPPER(REGEXP_REPLACE(c.cui, '[[:space:]]', '')) END), '')";

    public function getDescription(): string
    {
        return 'Creditor cui_key (canonical CUI) and uniqueness per lawyer on it';
    }

    public function up(Schema $schema): void
    {
        $duplicates = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT c.user_id, ' . self::CUI_KEY_SQL . ' AS k FROM creditor c'
            . ' GROUP BY c.user_id, k HAVING k IS NOT NULL AND COUNT(*) > 1) dup',
        );
        $this->abortIf($duplicates > 0, sprintf('%d creditor CUI(s) are stored twice for one lawyer under different spellings; merge them first.', $duplicates));

        $this->addSql('ALTER TABLE creditor ADD cui_key VARCHAR(20) DEFAULT NULL');
        $this->addSql('UPDATE creditor c SET c.cui_key = ' . self::CUI_KEY_SQL);
        $this->addSql('DROP INDEX uniq_creditor_user_cui ON creditor');
        $this->addSql('CREATE UNIQUE INDEX uniq_creditor_user_cui_key ON creditor (user_id, cui_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_creditor_user_cui_key ON creditor');
        $this->addSql('CREATE UNIQUE INDEX uniq_creditor_user_cui ON creditor (user_id, cui)');
        $this->addSql('ALTER TABLE creditor DROP cui_key');
    }
}
