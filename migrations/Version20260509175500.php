<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pas 2.4 — Rename `taxId`→`cui` și `tradeRegistryNumber`→`onrcNumber` pe Creditor și Debtor;
 * rename `Debtor.onrcStatus`→`Debtor.anafStatus` (typed enum AnafStatus); add Debtor ANAF/BPI fields.
 *
 * - Creditor.tax_id → Creditor.cui (varchar 20, nullable)
 * - Creditor.trade_registry_number → Creditor.onrc_number (varchar 50, nullable)
 * - Debtor.tax_id → Debtor.cui (varchar 20, nullable)
 * - Debtor.trade_registry_number → Debtor.onrc_number (varchar 50, nullable)
 * - Debtor.onrc_status → Debtor.anaf_status (varchar 20, nullable; payload neschimbat — string-uri ACTIV/INACTIV/RADIAT)
 * - Debtor: + anaf_checked_at, in_insolvency (NOT NULL DEFAULT 0), insolvency_checked_at,
 *           bpi_verified_note (varchar 500), bpi_proof_document_id (FK la document.id, ON DELETE SET NULL)
 * - Unique index Creditor: (user_id, tax_id) → (user_id, cui)
 *
 * Folosim `CHANGE COLUMN` (MySQL) ca să păstrăm datele din fixtures dev/test.
 */
final class Version20260509175500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pas 2.4 — Rename taxId→cui & tradeRegistryNumber→onrcNumber on Creditor/Debtor; add Debtor ANAF/BPI fields.';
    }

    public function up(Schema $schema): void
    {
        // Creditor: rename + recreate unique index on (user_id, cui).
        $this->addSql('DROP INDEX uniq_creditor_user_tax_id ON creditor');
        $this->addSql('ALTER TABLE creditor CHANGE tax_id cui VARCHAR(20) DEFAULT NULL, CHANGE trade_registry_number onrc_number VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_creditor_user_cui ON creditor (user_id, cui)');

        // Debtor: rename existing columns (preserve data) + add new ANAF/BPI columns.
        $this->addSql('ALTER TABLE debtor CHANGE tax_id cui VARCHAR(20) DEFAULT NULL, CHANGE trade_registry_number onrc_number VARCHAR(50) DEFAULT NULL, CHANGE onrc_status anaf_status VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE debtor ADD anaf_checked_at DATETIME DEFAULT NULL, ADD in_insolvency TINYINT(1) DEFAULT 0 NOT NULL, ADD insolvency_checked_at DATETIME DEFAULT NULL, ADD bpi_verified_note VARCHAR(500) DEFAULT NULL, ADD bpi_proof_document_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE debtor ADD CONSTRAINT FK_EDCC8CAE6679949B FOREIGN KEY (bpi_proof_document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_EDCC8CAE6679949B ON debtor (bpi_proof_document_id)');
    }

    public function down(Schema $schema): void
    {
        // Reverse Debtor changes first (FK depends on bpi_proof_document_id).
        $this->addSql('ALTER TABLE debtor DROP FOREIGN KEY FK_EDCC8CAE6679949B');
        $this->addSql('DROP INDEX IDX_EDCC8CAE6679949B ON debtor');
        $this->addSql('ALTER TABLE debtor DROP anaf_checked_at, DROP in_insolvency, DROP insolvency_checked_at, DROP bpi_verified_note, DROP bpi_proof_document_id');
        $this->addSql('ALTER TABLE debtor CHANGE cui tax_id VARCHAR(20) DEFAULT NULL, CHANGE onrc_number trade_registry_number VARCHAR(50) DEFAULT NULL, CHANGE anaf_status onrc_status VARCHAR(20) DEFAULT NULL');

        // Reverse Creditor.
        $this->addSql('DROP INDEX uniq_creditor_user_cui ON creditor');
        $this->addSql('ALTER TABLE creditor CHANGE cui tax_id VARCHAR(20) DEFAULT NULL, CHANGE onrc_number trade_registry_number VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_creditor_user_tax_id ON creditor (user_id, tax_id)');
    }
}
