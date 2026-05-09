<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pas 2.5.4 — adaugă audit_log.category (nullable VARCHAR 50) + index pentru
 * categorisarea apelurilor de AI-extraction (AuditLogService::CATEGORY_AI_EXTRACTION)
 * și viitoare alte categorii (per REVIZIE Faza 2 GDPR).
 */
final class Version20260509223127 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pas 2.5.4 — audit_log.category (nullable, indexed) pentru AI_EXTRACTION + viitoare categorii GDPR';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log ADD category VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_audit_log_category ON audit_log (category)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_log_category ON audit_log');
        $this->addSql('ALTER TABLE audit_log DROP category');
    }
}
