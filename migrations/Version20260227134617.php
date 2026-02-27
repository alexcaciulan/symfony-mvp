<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260227134617 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE court_portal_event (id INT AUTO_INCREMENT NOT NULL, event_type VARCHAR(30) NOT NULL, event_date DATE DEFAULT NULL, description VARCHAR(1000) NOT NULL, solutie VARCHAR(500) DEFAULT NULL, solutie_sumar VARCHAR(255) DEFAULT NULL, raw_data JSON DEFAULT NULL, detected_at DATETIME NOT NULL, notified TINYINT NOT NULL, created_at DATETIME NOT NULL, legal_case_id INT NOT NULL, INDEX IDX_E17B80D682B4A9B (legal_case_id), INDEX idx_portal_event_dedup (legal_case_id, event_type, event_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE court_portal_event ADD CONSTRAINT FK_E17B80D682B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE court ADD portal_code VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_63AE193FFDE3F735 ON court (portal_code)');
        $this->addSql('ALTER TABLE legal_case ADD last_portal_check_at DATETIME DEFAULT NULL');
        $this->addSql('DROP INDEX IDX_75EA56E016BA31DB ON messenger_messages');
        $this->addSql('DROP INDEX IDX_75EA56E0FB7336F0 ON messenger_messages');
        $this->addSql('DROP INDEX IDX_75EA56E0E3BD61CE ON messenger_messages');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE court_portal_event DROP FOREIGN KEY FK_E17B80D682B4A9B');
        $this->addSql('DROP TABLE court_portal_event');
        $this->addSql('DROP INDEX UNIQ_63AE193FFDE3F735 ON court');
        $this->addSql('ALTER TABLE court DROP portal_code');
        $this->addSql('ALTER TABLE legal_case DROP last_portal_check_at');
        $this->addSql('DROP INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
    }
}
