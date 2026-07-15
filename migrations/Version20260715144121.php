<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260715144121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification: add readAt + dedupKey (unique) + hot-path indexes; canonicalize legacy portal_update type to portal_event.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD read_at DATETIME DEFAULT NULL, ADD dedup_key VARCHAR(191) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BF5476CA44F613DD ON notification (dedup_key)');
        $this->addSql('CREATE INDEX idx_notification_user_read ON notification (user_id, is_read)');
        $this->addSql('CREATE INDEX idx_notification_user_created ON notification (user_id, created_at)');
        // Backfill readAt for rows already marked read, so the new lifecycle field is consistent.
        $this->addSql("UPDATE notification SET read_at = created_at WHERE is_read = 1 AND read_at IS NULL");
        // Canonicalize the historical type value onto the NotificationType enum backing value.
        $this->addSql("UPDATE notification SET type = 'portal_event' WHERE type = 'portal_update'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE notification SET type = 'portal_update' WHERE type = 'portal_event'");
        $this->addSql('DROP INDEX UNIQ_BF5476CA44F613DD ON notification');
        $this->addSql('DROP INDEX idx_notification_user_read ON notification');
        $this->addSql('DROP INDEX idx_notification_user_created ON notification');
        $this->addSql('ALTER TABLE notification DROP read_at, DROP dedup_key');
    }
}
