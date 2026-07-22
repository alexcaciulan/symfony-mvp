<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI-only extraction pipeline: per-user pipeline flag and agreement stamp,
 * per-document content hash, detected type and failure reason.
 */
final class Version20260721152215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds user.extraction_pipeline (+ AI processing agreement) and document.content_hash / detected_type / extraction_failure_reason';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD content_hash VARCHAR(64) DEFAULT NULL, ADD detected_type VARCHAR(30) DEFAULT NULL, ADD detected_type_confidence NUMERIC(3, 2) DEFAULT NULL, ADD extraction_failure_reason VARCHAR(30) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_document_uploader_content_hash ON document (uploaded_by_id, content_hash)');
        $this->addSql('ALTER TABLE user ADD extraction_pipeline VARCHAR(20) DEFAULT \'AI_ONLY\' NOT NULL, ADD ai_processing_agreement_at DATETIME DEFAULT NULL, ADD ai_processing_agreement_version VARCHAR(20) DEFAULT NULL');
        // The column default only governs rows inserted from here on. Existing
        // accounts must keep the cascade they were created under, so pin them
        // explicitly instead of letting the default silently switch engines.
        $this->addSql('UPDATE user SET extraction_pipeline = \'LEGACY_CASCADE\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_document_uploader_content_hash ON document');
        $this->addSql('ALTER TABLE document DROP content_hash, DROP detected_type, DROP detected_type_confidence, DROP extraction_failure_reason');
        $this->addSql('ALTER TABLE user DROP extraction_pipeline, DROP ai_processing_agreement_at, DROP ai_processing_agreement_version');
    }
}
