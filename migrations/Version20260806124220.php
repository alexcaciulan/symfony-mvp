<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tracking for the stamp-duty reminders: how many went out, when the last one did,
 * and whether the lawyer asked us to stop on this case.
 *
 * The counter lives on the case rather than being derived from the notifications
 * table so the schedule survives pruning and a repeated run cannot double-send.
 */
final class Version20260806124220 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stamp-duty reminder tracking to legal_case';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD stamp_duty_reminders_sent SMALLINT DEFAULT 0 NOT NULL, ADD stamp_duty_last_reminder_at DATE DEFAULT NULL, ADD stamp_duty_reminders_muted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP stamp_duty_reminders_sent, DROP stamp_duty_last_reminder_at, DROP stamp_duty_reminders_muted_at');
    }
}
