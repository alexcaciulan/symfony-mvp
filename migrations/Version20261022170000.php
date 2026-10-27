<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add court_email_address (several confirmable addresses per court) and court.portal_site_id; drop the unused court.email';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE court_email_address (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(254) NOT NULL, purpose VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, source VARCHAR(20) NOT NULL, section_label VARCHAR(255) DEFAULT NULL, filing_default TINYINT DEFAULT 0 NOT NULL, source_url VARCHAR(500) DEFAULT NULL, context LONGTEXT DEFAULT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME DEFAULT NULL, missing_since DATETIME DEFAULT NULL, validated_at DATETIME DEFAULT NULL, validation_statement VARCHAR(500) DEFAULT NULL, invalidated_at DATETIME DEFAULT NULL, invalid_reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, court_id INT NOT NULL, validated_by_id INT DEFAULT NULL, INDEX IDX_89FF8C2FE3184009 (court_id), INDEX IDX_89FF8C2FC69DE5E5 (validated_by_id), INDEX idx_court_email_address_status (status), UNIQUE INDEX uniq_court_email_address_court_email (court_id, email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE court_email_address ADD CONSTRAINT FK_89FF8C2FE3184009 FOREIGN KEY (court_id) REFERENCES court (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE court_email_address ADD CONSTRAINT FK_89FF8C2FC69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE court ADD portal_site_id INT DEFAULT NULL, DROP email');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_63AE193FF778CE5F ON court (portal_site_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE court_email_address DROP FOREIGN KEY FK_89FF8C2FE3184009');
        $this->addSql('ALTER TABLE court_email_address DROP FOREIGN KEY FK_89FF8C2FC69DE5E5');
        $this->addSql('DROP TABLE court_email_address');
        $this->addSql('DROP INDEX UNIQ_63AE193FF778CE5F ON court');
        $this->addSql('ALTER TABLE court ADD email VARCHAR(255) DEFAULT NULL, DROP portal_site_id');
    }
}
