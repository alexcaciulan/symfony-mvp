<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Composite indexes for the global deadline agenda. Until now legal_deadline was
 * indexed only on its foreign keys, so any filter on a date range scanned the table.
 */
final class Version20260725211240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite indexes on legal_deadline for the agenda queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_deadline_completed_date ON legal_deadline (completed, deadline_date)');
        $this->addSql('CREATE INDEX idx_deadline_case_completed_date ON legal_deadline (legal_case_id, completed, deadline_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_deadline_completed_date ON legal_deadline');
        $this->addSql('DROP INDEX idx_deadline_case_completed_date ON legal_deadline');
    }
}
