<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260219154730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Step 1: Drop all foreign keys that reference UUID primary keys
        $this->addSql('ALTER TABLE case_status_history DROP FOREIGN KEY FK_4ABD378B82B4A9B');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7682B4A9B');
        $this->addSql('ALTER TABLE legal_case DROP FOREIGN KEY FK_557377B3E3184009');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA82B4A9B');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D82B4A9B');

        // Step 2: Change all primary key columns from BINARY(16) to INT AUTO_INCREMENT
        $this->addSql('ALTER TABLE audit_log CHANGE id id INT AUTO_INCREMENT NOT NULL');
        $this->addSql('ALTER TABLE court CHANGE id id INT AUTO_INCREMENT NOT NULL');
        $this->addSql('ALTER TABLE legal_case CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE court_id court_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE case_status_history CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE legal_case_id legal_case_id INT NOT NULL');
        $this->addSql('ALTER TABLE document CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE legal_case_id legal_case_id INT NOT NULL');
        $this->addSql('ALTER TABLE notification CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE legal_case_id legal_case_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE payment CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE legal_case_id legal_case_id INT NOT NULL');

        // Step 3: Re-add foreign keys
        $this->addSql('ALTER TABLE case_status_history ADD CONSTRAINT FK_4ABD378B82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7682B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE legal_case ADD CONSTRAINT FK_557377B3E3184009 FOREIGN KEY (court_id) REFERENCES court (id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
    }

    public function down(Schema $schema): void
    {
        // Step 1: Drop foreign keys
        $this->addSql('ALTER TABLE case_status_history DROP FOREIGN KEY FK_4ABD378B82B4A9B');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7682B4A9B');
        $this->addSql('ALTER TABLE legal_case DROP FOREIGN KEY FK_557377B3E3184009');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA82B4A9B');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D82B4A9B');

        // Step 2: Revert columns back to BINARY(16)
        $this->addSql('ALTER TABLE audit_log CHANGE id id BINARY(16) NOT NULL');
        $this->addSql('ALTER TABLE court CHANGE id id BINARY(16) NOT NULL');
        $this->addSql('ALTER TABLE legal_case CHANGE id id BINARY(16) NOT NULL, CHANGE court_id court_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE case_status_history CHANGE id id BINARY(16) NOT NULL, CHANGE legal_case_id legal_case_id BINARY(16) NOT NULL');
        $this->addSql('ALTER TABLE document CHANGE id id BINARY(16) NOT NULL, CHANGE legal_case_id legal_case_id BINARY(16) NOT NULL');
        $this->addSql('ALTER TABLE notification CHANGE id id BINARY(16) NOT NULL, CHANGE legal_case_id legal_case_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE payment CHANGE id id BINARY(16) NOT NULL, CHANGE legal_case_id legal_case_id BINARY(16) NOT NULL');

        // Step 3: Re-add foreign keys
        $this->addSql('ALTER TABLE case_status_history ADD CONSTRAINT FK_4ABD378B82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7682B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE legal_case ADD CONSTRAINT FK_557377B3E3184009 FOREIGN KEY (court_id) REFERENCES court (id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
    }
}
