<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260219161827 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(50) NOT NULL, entity_type VARCHAR(50) NOT NULL, entity_id VARCHAR(50) DEFAULT NULL, old_data JSON DEFAULT NULL, new_data JSON DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_F6E1C0F5A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE case_status_history (id INT AUTO_INCREMENT NOT NULL, old_status VARCHAR(30) NOT NULL, new_status VARCHAR(30) NOT NULL, reason VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, legal_case_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_4ABD378B82B4A9B (legal_case_id), INDEX IDX_4ABD378BB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE court (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(255) DEFAULT NULL, county VARCHAR(50) NOT NULL, email VARCHAR(255) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, type VARCHAR(20) NOT NULL, active TINYINT(1) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE document (id INT AUTO_INCREMENT NOT NULL, document_type VARCHAR(30) NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, file_size INT NOT NULL, mime_type VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, legal_case_id INT NOT NULL, uploaded_by_id INT NOT NULL, INDEX IDX_D8698A7682B4A9B (legal_case_id), INDEX IDX_D8698A76A2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE legal_case (id INT AUTO_INCREMENT NOT NULL, case_number VARCHAR(30) DEFAULT NULL, current_step SMALLINT NOT NULL, status VARCHAR(30) NOT NULL, county VARCHAR(50) DEFAULT NULL, claimant_type VARCHAR(10) DEFAULT NULL, claimant_data JSON DEFAULT NULL, has_lawyer TINYINT(1) NOT NULL, lawyer_data JSON DEFAULT NULL, defendants JSON DEFAULT NULL, claim_amount NUMERIC(10, 2) DEFAULT NULL, currency VARCHAR(3) NOT NULL, claim_description LONGTEXT DEFAULT NULL, due_date DATE DEFAULT NULL, legal_basis VARCHAR(30) DEFAULT NULL, interest_type VARCHAR(20) NOT NULL, interest_rate NUMERIC(5, 2) DEFAULT NULL, interest_start_date DATE DEFAULT NULL, request_court_costs TINYINT(1) NOT NULL, evidence_description LONGTEXT DEFAULT NULL, has_witnesses TINYINT(1) NOT NULL, witnesses JSON DEFAULT NULL, request_oral_debate TINYINT(1) NOT NULL, court_fee NUMERIC(10, 2) DEFAULT NULL, platform_fee NUMERIC(10, 2) DEFAULT NULL, total_fee NUMERIC(10, 2) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, user_id INT NOT NULL, court_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_557377B33F7E58FD (case_number), INDEX IDX_557377B3A76ED395 (user_id), INDEX IDX_557377B3E3184009 (court_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(50) NOT NULL, channel VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, message VARCHAR(1000) NOT NULL, resource_link VARCHAR(500) DEFAULT NULL, is_read TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, legal_case_id INT DEFAULT NULL, INDEX IDX_BF5476CAA76ED395 (user_id), INDEX IDX_BF5476CA82B4A9B (legal_case_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payment (id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(10, 2) NOT NULL, payment_type VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, payment_method VARCHAR(30) DEFAULT NULL, external_reference VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, legal_case_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_6D28840D82B4A9B (legal_case_id), INDEX IDX_6D28840DA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F5A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE case_status_history ADD CONSTRAINT FK_4ABD378B82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE case_status_history ADD CONSTRAINT FK_4ABD378BB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7682B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE legal_case ADD CONSTRAINT FK_557377B3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE legal_case ADD CONSTRAINT FK_557377B3E3184009 FOREIGN KEY (court_id) REFERENCES court (id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840DA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE user ADD type VARCHAR(20) DEFAULT NULL, ADD cnp VARCHAR(13) DEFAULT NULL, ADD cui VARCHAR(20) DEFAULT NULL, ADD company_name VARCHAR(255) DEFAULT NULL, ADD bar_number VARCHAR(50) DEFAULT NULL, ADD phone VARCHAR(20) DEFAULT NULL, ADD street VARCHAR(255) DEFAULT NULL, ADD street_number VARCHAR(20) DEFAULT NULL, ADD block VARCHAR(20) DEFAULT NULL, ADD staircase VARCHAR(10) DEFAULT NULL, ADD apartment VARCHAR(10) DEFAULT NULL, ADD city VARCHAR(100) DEFAULT NULL, ADD county VARCHAR(50) DEFAULT NULL, ADD postal_code VARCHAR(10) DEFAULT NULL, ADD deleted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F5A76ED395');
        $this->addSql('ALTER TABLE case_status_history DROP FOREIGN KEY FK_4ABD378B82B4A9B');
        $this->addSql('ALTER TABLE case_status_history DROP FOREIGN KEY FK_4ABD378BB03A8386');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7682B4A9B');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76A2B28FE8');
        $this->addSql('ALTER TABLE legal_case DROP FOREIGN KEY FK_557377B3A76ED395');
        $this->addSql('ALTER TABLE legal_case DROP FOREIGN KEY FK_557377B3E3184009');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAA76ED395');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA82B4A9B');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D82B4A9B');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840DA76ED395');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE case_status_history');
        $this->addSql('DROP TABLE court');
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE legal_case');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE payment');
        $this->addSql('ALTER TABLE user DROP type, DROP cnp, DROP cui, DROP company_name, DROP bar_number, DROP phone, DROP street, DROP street_number, DROP block, DROP staircase, DROP apartment, DROP city, DROP county, DROP postal_code, DROP deleted_at');
    }
}
