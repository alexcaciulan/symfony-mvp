<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the stamp-duty reminder tracking added by Version20260806124220.
 *
 * The reminders assumed the court answers a filed petition within a few weeks, so
 * they fired on a fixed schedule counted from filing. Courts take the file when its
 * turn comes, months later included, so the schedule described nothing real. Chasing
 * an unpaid duty is left to the blocked-case alert (BlockedCaseAlert::STAMP_DUTY_DUE),
 * which is driven by the state of the case and not by a calendar we invented.
 */
final class Version20260821090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop stamp-duty reminder tracking from legal_case';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP stamp_duty_reminders_sent, DROP stamp_duty_last_reminder_at, DROP stamp_duty_reminders_muted_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD stamp_duty_reminders_sent SMALLINT DEFAULT 0 NOT NULL, ADD stamp_duty_last_reminder_at DATE DEFAULT NULL, ADD stamp_duty_reminders_muted_at DATETIME DEFAULT NULL');
    }
}
