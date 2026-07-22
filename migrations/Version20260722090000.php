<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Claim positions: a case can now carry several invoices, each with its own due
 * date, so interest accrues per position instead of on the aggregate from the
 * earliest one.
 */
final class Version20260722090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates claim_item and backfills one position per existing case from the case scalars';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE claim_item (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(30) NOT NULL, document_number VARCHAR(100) DEFAULT NULL, document_date DATE DEFAULT NULL, due_date DATE DEFAULT NULL, amount NUMERIC(12, 2) NOT NULL, currency VARCHAR(3) NOT NULL, amount_ron NUMERIC(12, 2) DEFAULT NULL, exchange_rate NUMERIC(10, 4) DEFAULT NULL, exchange_rate_date DATE DEFAULT NULL, needs_manual_fx TINYINT DEFAULT 0 NOT NULL, paid_amount NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, dedup_key VARCHAR(100) NOT NULL, cause_reference VARCHAR(150) DEFAULT NULL, confirmed_by_lawyer TINYINT DEFAULT 0 NOT NULL, excluded_by_lawyer TINYINT DEFAULT 0 NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, legal_case_id INT NOT NULL, debtor_id INT DEFAULT NULL, source_document_id INT DEFAULT NULL, cause_document_id INT DEFAULT NULL, INDEX IDX_5114B23A82B4A9B (legal_case_id), INDEX IDX_5114B23AB043EC6B (debtor_id), INDEX IDX_5114B23AFF402897 (source_document_id), INDEX IDX_5114B23A28E77673 (cause_document_id), INDEX idx_claim_item_case_due_date (legal_case_id, due_date), UNIQUE INDEX uniq_claim_item_case_dedup (legal_case_id, dedup_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE claim_item ADD CONSTRAINT FK_5114B23A82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE claim_item ADD CONSTRAINT FK_5114B23AB043EC6B FOREIGN KEY (debtor_id) REFERENCES debtor (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE claim_item ADD CONSTRAINT FK_5114B23AFF402897 FOREIGN KEY (source_document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE claim_item ADD CONSTRAINT FK_5114B23A28E77673 FOREIGN KEY (cause_document_id) REFERENCES document (id) ON DELETE SET NULL');

        // One position per existing case, carrying exactly the figures the case
        // already computed with, so every existing case keeps producing the same
        // numbers. `amount_ron` is filled only for RON cases: a case filed in a
        // foreign currency before per-position conversion existed has no rate
        // behind its stored amount, so it is flagged for a manual rate rather
        // than treated as if it had one.
        $this->addSql(<<<'SQL'
            INSERT INTO claim_item (
                legal_case_id, kind, document_number, document_date, due_date,
                amount, currency, amount_ron, needs_manual_fx, paid_amount,
                dedup_key, cause_reference, confirmed_by_lawyer, excluded_by_lawyer, created_at
            )
            SELECT
                c.id,
                'invoice',
                c.invoice_number,
                c.invoice_date,
                c.due_date,
                COALESCE(c.amount, 0),
                COALESCE(c.currency, 'RON'),
                CASE WHEN COALESCE(c.currency, 'RON') = 'RON' THEN COALESCE(c.amount, 0) ELSE NULL END,
                CASE WHEN COALESCE(c.currency, 'RON') = 'RON' THEN 0 ELSE 1 END,
                0,
                CONCAT('legacy:', c.id),
                c.contract_number,
                1,
                0,
                COALESCE(c.created_at, NOW())
            FROM legal_case c
            WHERE c.amount IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE claim_item DROP FOREIGN KEY FK_5114B23A82B4A9B');
        $this->addSql('ALTER TABLE claim_item DROP FOREIGN KEY FK_5114B23AB043EC6B');
        $this->addSql('ALTER TABLE claim_item DROP FOREIGN KEY FK_5114B23AFF402897');
        $this->addSql('ALTER TABLE claim_item DROP FOREIGN KEY FK_5114B23A28E77673');
        $this->addSql('DROP TABLE claim_item');
    }
}
