<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pas 4.0.1 — adaugă index compound `(entity_type, entity_id)` pe audit_log pentru
 * a evita full-table scan la `AuditLogRepository::findByCase()` (overview dosar).
 * La adâncimea de trafic prod (100k+ rows audit), fără acest index query-ul devine O(n).
 */
final class Version20260512223800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pas 4.0.1 — audit_log compound index (entity_type, entity_id) pentru findByCase';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_audit_log_entity ON audit_log (entity_type, entity_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_log_entity ON audit_log');
    }
}
