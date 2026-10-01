<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Debtor becomes the company, kept per lawyer; its part in a case and what was
 * checked for that case move to legal_case_debtor.
 *
 * Every existing debtor row becomes a company of its own and gets exactly one
 * link, with the same id, so claim_item.debtor_id stays valid as is. Existing
 * rows are not merged by CUI: merging would change the identity a filed case
 * shows. Written by hand: the diff tool cannot move the data.
 */
final class Version20260930150000 extends AbstractMigration
{
    /** Canonical CUI, the SQL twin of App\Service\Party\CuiNormalizer::canonical(). */
    private const CUI_KEY_SQL = "NULLIF(TRIM(LEADING '0' FROM CASE WHEN UPPER(REGEXP_REPLACE(d.cui, '[[:space:]]', '')) LIKE 'RO%' THEN SUBSTRING(UPPER(REGEXP_REPLACE(d.cui, '[[:space:]]', '')), 3) ELSE UPPER(REGEXP_REPLACE(d.cui, '[[:space:]]', '')) END), '')";

    public function getDescription(): string
    {
        return 'Debtor as a per-lawyer company; case link and per-case checks in legal_case_debtor';
    }

    public function isTransactional(): bool
    {
        // Every DDL statement commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('legal_case_debtor'), 'legal_case_debtor already exists.');

        // Debtors whose case is gone (deleted with foreign key checks off) have
        // no case and no lawyer to belong to, and nothing can reach them.
        $orphans = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM debtor d LEFT JOIN legal_case lc ON lc.id = d.legal_case_id WHERE lc.id IS NULL');
        if ($orphans > 0) {
            $this->write(sprintf('Removing %d debtor rows whose case no longer exists.', $orphans));
            $this->addSql('DELETE d FROM debtor d LEFT JOIN legal_case lc ON lc.id = d.legal_case_id WHERE lc.id IS NULL');
        }

        $this->addSql('CREATE TABLE legal_case_debtor (id INT AUTO_INCREMENT NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, anaf_status VARCHAR(20) DEFAULT NULL, anaf_checked_at DATETIME DEFAULT NULL, in_insolvency TINYINT DEFAULT 0 NOT NULL, insolvency_checked_at DATETIME DEFAULT NULL, bpi_verified_note VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, legal_case_id INT NOT NULL, debtor_id INT NOT NULL, bpi_proof_document_id INT DEFAULT NULL, INDEX IDX_51E466E682B4A9B (legal_case_id), INDEX IDX_51E466E6B043EC6B (debtor_id), INDEX IDX_51E466E66679949B (bpi_proof_document_id), UNIQUE INDEX uniq_lcd_case_debtor (legal_case_id, debtor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        // One link per debtor, same id, order kept within each case.
        $this->addSql('INSERT INTO legal_case_debtor (id, legal_case_id, debtor_id, position, anaf_status, anaf_checked_at, in_insolvency, insolvency_checked_at, bpi_verified_note, bpi_proof_document_id, created_at, updated_at) SELECT id, legal_case_id, id, ROW_NUMBER() OVER (PARTITION BY legal_case_id ORDER BY id) - 1, anaf_status, anaf_checked_at, in_insolvency, insolvency_checked_at, bpi_verified_note, bpi_proof_document_id, created_at, updated_at FROM debtor');

        $this->addSql('ALTER TABLE legal_case_debtor ADD CONSTRAINT FK_51E466E682B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE legal_case_debtor ADD CONSTRAINT FK_51E466E6B043EC6B FOREIGN KEY (debtor_id) REFERENCES debtor (id)');
        $this->addSql('ALTER TABLE legal_case_debtor ADD CONSTRAINT FK_51E466E66679949B FOREIGN KEY (bpi_proof_document_id) REFERENCES document (id) ON DELETE SET NULL');

        // The company belongs to the lawyer who owned the case.
        $this->addSql('ALTER TABLE debtor ADD user_id INT DEFAULT NULL, ADD cui_key VARCHAR(20) DEFAULT NULL');
        $this->addSql('UPDATE debtor d JOIN legal_case lc ON lc.id = d.legal_case_id SET d.user_id = lc.user_id, d.cui_key = ' . self::CUI_KEY_SQL);

        // claim_item.debtor_id now names the link; the ids are the same.
        $this->addSql('ALTER TABLE claim_item DROP FOREIGN KEY FK_5114B23AB043EC6B');
        $this->addSql('ALTER TABLE claim_item ADD CONSTRAINT FK_5114B23AB043EC6B FOREIGN KEY (debtor_id) REFERENCES legal_case_debtor (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE debtor DROP FOREIGN KEY FK_EDCC8CAE6679949B');
        $this->addSql('ALTER TABLE debtor DROP FOREIGN KEY FK_EDCC8CAE82B4A9B');
        $this->addSql('DROP INDEX IDX_EDCC8CAE82B4A9B ON debtor');
        $this->addSql('DROP INDEX IDX_EDCC8CAE6679949B ON debtor');
        $this->addSql('ALTER TABLE debtor DROP legal_case_id, DROP anaf_status, DROP anaf_checked_at, DROP in_insolvency, DROP insolvency_checked_at, DROP bpi_verified_note, DROP bpi_proof_document_id');
        $this->addSql('ALTER TABLE debtor MODIFY user_id INT NOT NULL');
        $this->addSql('ALTER TABLE debtor ADD CONSTRAINT FK_EDCC8CAEA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_EDCC8CAEA76ED395 ON debtor (user_id)');
        $this->addSql('CREATE INDEX idx_debtor_user_cui_key ON debtor (user_id, cui_key)');
    }

    public function down(Schema $schema): void
    {
        // Reversible only while every company has at most one link, as right
        // after up(); once a company is reused, a case would lose its debtor.
        $shared = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM (SELECT debtor_id FROM legal_case_debtor GROUP BY debtor_id HAVING COUNT(*) > 1) t');
        $this->abortIf($shared > 0, 'Some debtors are used by several cases; restore from backup instead.');
        $orphans = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM debtor d LEFT JOIN legal_case_debtor l ON l.debtor_id = d.id WHERE l.id IS NULL');
        $this->abortIf($orphans > 0, 'Some debtors are in no case; restore from backup instead.');

        $this->addSql('ALTER TABLE debtor ADD legal_case_id INT DEFAULT NULL, ADD anaf_status VARCHAR(20) DEFAULT NULL, ADD anaf_checked_at DATETIME DEFAULT NULL, ADD in_insolvency TINYINT DEFAULT 0 NOT NULL, ADD insolvency_checked_at DATETIME DEFAULT NULL, ADD bpi_verified_note VARCHAR(500) DEFAULT NULL, ADD bpi_proof_document_id INT DEFAULT NULL');
        $this->addSql('UPDATE debtor d JOIN legal_case_debtor l ON l.debtor_id = d.id SET d.legal_case_id = l.legal_case_id, d.anaf_status = l.anaf_status, d.anaf_checked_at = l.anaf_checked_at, d.in_insolvency = l.in_insolvency, d.insolvency_checked_at = l.insolvency_checked_at, d.bpi_verified_note = l.bpi_verified_note, d.bpi_proof_document_id = l.bpi_proof_document_id');
        // The key comes off before the remap: a link id that differs from its
        // company id is not a valid link id once rewritten.
        $this->addSql('ALTER TABLE claim_item DROP FOREIGN KEY FK_5114B23AB043EC6B');
        $this->addSql('UPDATE claim_item c JOIN legal_case_debtor l ON l.id = c.debtor_id SET c.debtor_id = l.debtor_id');
        $this->addSql('ALTER TABLE claim_item ADD CONSTRAINT FK_5114B23AB043EC6B FOREIGN KEY (debtor_id) REFERENCES debtor (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE debtor DROP FOREIGN KEY FK_EDCC8CAEA76ED395');
        $this->addSql('DROP INDEX IDX_EDCC8CAEA76ED395 ON debtor');
        $this->addSql('DROP INDEX idx_debtor_user_cui_key ON debtor');
        $this->addSql('ALTER TABLE debtor DROP user_id, DROP cui_key, MODIFY legal_case_id INT NOT NULL');
        $this->addSql('ALTER TABLE debtor ADD CONSTRAINT FK_EDCC8CAE82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE debtor ADD CONSTRAINT FK_EDCC8CAE6679949B FOREIGN KEY (bpi_proof_document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_EDCC8CAE82B4A9B ON debtor (legal_case_id)');
        $this->addSql('CREATE INDEX IDX_EDCC8CAE6679949B ON debtor (bpi_proof_document_id)');
        $this->addSql('DROP TABLE legal_case_debtor');
    }
}
