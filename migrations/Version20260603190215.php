<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260603190215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add plan.is_trial flag (billing trial plan). Subscription/Invoice status+type columns switch to enumType in code only (VARCHAR unchanged, no SQL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan ADD is_trial TINYINT(1) DEFAULT 0 NOT NULL');

        // The status/type columns switch to enumType in code (VARCHAR unchanged).
        // Reconcile legacy values that have no matching enum case so Doctrine can
        // hydrate them (no-op on a fresh database, defensive for seeded environments).
        $this->addSql("UPDATE subscription SET status = 'canceled' WHERE status = 'cancelled'");
        $this->addSql("UPDATE subscription SET status = 'suspended' WHERE status = 'expired'");
        $this->addSql("UPDATE invoice SET status = 'canceled' WHERE status = 'failed'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan DROP is_trial');
    }
}
