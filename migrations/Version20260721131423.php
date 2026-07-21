<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plan-change support: invoice.target_plan_id carries the plan an upgrade applies
 * on settlement, subscription.pending_plan_id the plan a downgrade applies at the
 * next renewal. All columns nullable, so no backfill is needed.
 */
final class Version20260721131423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add plan-change columns to invoice and subscription';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD target_plan_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_9065174423F1BBF0 FOREIGN KEY (target_plan_id) REFERENCES plan (id)');
        $this->addSql('CREATE INDEX IDX_9065174423F1BBF0 ON invoice (target_plan_id)');
        $this->addSql('ALTER TABLE subscription ADD plan_changed_at DATETIME DEFAULT NULL, ADD pending_plan_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D323F6F50A FOREIGN KEY (pending_plan_id) REFERENCES plan (id)');
        $this->addSql('CREATE INDEX IDX_A3C664D323F6F50A ON subscription (pending_plan_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_9065174423F1BBF0');
        $this->addSql('DROP INDEX IDX_9065174423F1BBF0 ON invoice');
        $this->addSql('ALTER TABLE invoice DROP target_plan_id');
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D323F6F50A');
        $this->addSql('DROP INDEX IDX_A3C664D323F6F50A ON subscription');
        $this->addSql('ALTER TABLE subscription DROP plan_changed_at, DROP pending_plan_id');
    }
}
