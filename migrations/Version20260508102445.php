<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508102445 extends AbstractMigration
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
        $this->addSql('CREATE TABLE court (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(255) DEFAULT NULL, county VARCHAR(50) NOT NULL, email VARCHAR(255) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, type VARCHAR(20) NOT NULL, active TINYINT NOT NULL, portal_code VARCHAR(100) DEFAULT NULL, UNIQUE INDEX UNIQ_63AE193FFDE3F735 (portal_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE court_portal_event (id INT AUTO_INCREMENT NOT NULL, event_type VARCHAR(30) NOT NULL, event_date DATE DEFAULT NULL, description VARCHAR(1000) NOT NULL, solutie VARCHAR(500) DEFAULT NULL, solutie_sumar VARCHAR(255) DEFAULT NULL, raw_data JSON DEFAULT NULL, detected_at DATETIME NOT NULL, notified TINYINT NOT NULL, created_at DATETIME NOT NULL, legal_case_id INT NOT NULL, INDEX IDX_E17B80D682B4A9B (legal_case_id), INDEX idx_portal_event_dedup (legal_case_id, event_type, event_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE creditor (id INT AUTO_INCREMENT NOT NULL, person_type VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, tax_id VARCHAR(20) DEFAULT NULL, personal_id VARCHAR(13) DEFAULT NULL, trade_registry_number VARCHAR(50) DEFAULT NULL, address LONGTEXT NOT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(30) DEFAULT NULL, iban VARCHAR(34) DEFAULT NULL, legal_representative VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_3D82E92AA76ED395 (user_id), UNIQUE INDEX uniq_creditor_user_tax_id (user_id, tax_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE debtor (id INT AUTO_INCREMENT NOT NULL, person_type VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, tax_id VARCHAR(20) DEFAULT NULL, personal_id VARCHAR(13) DEFAULT NULL, trade_registry_number VARCHAR(50) DEFAULT NULL, address LONGTEXT NOT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(30) DEFAULT NULL, iban VARCHAR(34) DEFAULT NULL, administrator VARCHAR(255) DEFAULT NULL, onrc_status VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, legal_case_id INT NOT NULL, INDEX IDX_EDCC8CAE82B4A9B (legal_case_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE document (id INT AUTO_INCREMENT NOT NULL, document_type VARCHAR(30) NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, file_size INT NOT NULL, mime_type VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, extracted_data JSON DEFAULT NULL, extraction_status VARCHAR(20) NOT NULL, extraction_confidence NUMERIC(3, 2) DEFAULT NULL, legal_case_id INT NOT NULL, uploaded_by_id INT NOT NULL, INDEX IDX_D8698A7682B4A9B (legal_case_id), INDEX IDX_D8698A76A2B28FE8 (uploaded_by_id), INDEX idx_document_extraction_status (extraction_status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE interest_rate_config (id INT AUTO_INCREMENT NOT NULL, valid_from DATE NOT NULL, reference_rate NUMERIC(5, 2) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_F43E751A7BD7DFB6 (valid_from), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE invoice (id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(8, 2) NOT NULL, type VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, paid_at DATETIME DEFAULT NULL, external_id VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, subscription_id INT DEFAULT NULL, legal_case_id INT DEFAULT NULL, INDEX IDX_90651744A76ED395 (user_id), INDEX IDX_906517449A1887DC (subscription_id), INDEX IDX_9065174482B4A9B (legal_case_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE legal_case (id INT AUTO_INCREMENT NOT NULL, case_number VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, relationship_type VARCHAR(20) DEFAULT NULL, amount NUMERIC(12, 2) DEFAULT NULL, currency VARCHAR(3) NOT NULL, calculated_interest NUMERIC(12, 2) DEFAULT NULL, stamp_duty NUMERIC(8, 2) DEFAULT NULL, due_date DATE DEFAULT NULL, payment_notice_date DATE DEFAULT NULL, court_case_number VARCHAR(50) DEFAULT NULL, hearing_date DATE DEFAULT NULL, final_ruling_date DATE DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, last_portal_check_at DATETIME DEFAULT NULL, user_id INT NOT NULL, court_id INT DEFAULT NULL, creditor_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_557377B33F7E58FD (case_number), INDEX IDX_557377B3A76ED395 (user_id), INDEX IDX_557377B3E3184009 (court_id), INDEX IDX_557377B3DF91AC92 (creditor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE legal_deadline (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, deadline_date DATE NOT NULL, description VARCHAR(255) DEFAULT NULL, priority VARCHAR(20) NOT NULL, completed TINYINT NOT NULL, completed_at DATETIME DEFAULT NULL, alert_sent7 TINYINT NOT NULL, alert_sent3 TINYINT NOT NULL, alert_sent1 TINYINT NOT NULL, alert_sent_expired TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, legal_case_id INT NOT NULL, INDEX IDX_1D8A033382B4A9B (legal_case_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(50) NOT NULL, channel VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, message VARCHAR(1000) NOT NULL, resource_link VARCHAR(500) DEFAULT NULL, is_read TINYINT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, legal_case_id INT DEFAULT NULL, INDEX IDX_BF5476CAA76ED395 (user_id), INDEX IDX_BF5476CA82B4A9B (legal_case_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE plan (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, price_monthly NUMERIC(8, 2) NOT NULL, included_cases INT NOT NULL, price_per_extra NUMERIC(8, 2) NOT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_DD5A5B7D5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reset_password_request (id INT AUTO_INCREMENT NOT NULL, selector VARCHAR(20) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_7CE748AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE subscription (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, current_period_start DATETIME NOT NULL, current_period_end DATETIME NOT NULL, cases_consumed INT NOT NULL, external_id VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, plan_id INT NOT NULL, INDEX IDX_A3C664D3A76ED395 (user_id), INDEX IDX_A3C664D3E899029B (plan_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, is_verified TINYINT NOT NULL, first_name VARCHAR(100) DEFAULT NULL, last_name VARCHAR(100) DEFAULT NULL, type VARCHAR(20) DEFAULT NULL, cnp VARCHAR(13) DEFAULT NULL, cui VARCHAR(20) DEFAULT NULL, company_name VARCHAR(255) DEFAULT NULL, bar_number VARCHAR(50) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, street VARCHAR(255) DEFAULT NULL, street_number VARCHAR(20) DEFAULT NULL, block VARCHAR(20) DEFAULT NULL, staircase VARCHAR(10) DEFAULT NULL, apartment VARCHAR(10) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, county VARCHAR(50) DEFAULT NULL, postal_code VARCHAR(10) DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F5A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE case_status_history ADD CONSTRAINT FK_4ABD378B82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE case_status_history ADD CONSTRAINT FK_4ABD378BB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE court_portal_event ADD CONSTRAINT FK_E17B80D682B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE creditor ADD CONSTRAINT FK_3D82E92AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE debtor ADD CONSTRAINT FK_EDCC8CAE82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7682B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_90651744A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_906517449A1887DC FOREIGN KEY (subscription_id) REFERENCES subscription (id)');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_9065174482B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE legal_case ADD CONSTRAINT FK_557377B3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE legal_case ADD CONSTRAINT FK_557377B3E3184009 FOREIGN KEY (court_id) REFERENCES court (id)');
        $this->addSql('ALTER TABLE legal_case ADD CONSTRAINT FK_557377B3DF91AC92 FOREIGN KEY (creditor_id) REFERENCES creditor (id)');
        $this->addSql('ALTER TABLE legal_deadline ADD CONSTRAINT FK_1D8A033382B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE reset_password_request ADD CONSTRAINT FK_7CE748AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D3E899029B FOREIGN KEY (plan_id) REFERENCES plan (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F5A76ED395');
        $this->addSql('ALTER TABLE case_status_history DROP FOREIGN KEY FK_4ABD378B82B4A9B');
        $this->addSql('ALTER TABLE case_status_history DROP FOREIGN KEY FK_4ABD378BB03A8386');
        $this->addSql('ALTER TABLE court_portal_event DROP FOREIGN KEY FK_E17B80D682B4A9B');
        $this->addSql('ALTER TABLE creditor DROP FOREIGN KEY FK_3D82E92AA76ED395');
        $this->addSql('ALTER TABLE debtor DROP FOREIGN KEY FK_EDCC8CAE82B4A9B');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7682B4A9B');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76A2B28FE8');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_90651744A76ED395');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_906517449A1887DC');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_9065174482B4A9B');
        $this->addSql('ALTER TABLE legal_case DROP FOREIGN KEY FK_557377B3A76ED395');
        $this->addSql('ALTER TABLE legal_case DROP FOREIGN KEY FK_557377B3E3184009');
        $this->addSql('ALTER TABLE legal_case DROP FOREIGN KEY FK_557377B3DF91AC92');
        $this->addSql('ALTER TABLE legal_deadline DROP FOREIGN KEY FK_1D8A033382B4A9B');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAA76ED395');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA82B4A9B');
        $this->addSql('ALTER TABLE reset_password_request DROP FOREIGN KEY FK_7CE748AA76ED395');
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D3A76ED395');
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D3E899029B');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE case_status_history');
        $this->addSql('DROP TABLE court');
        $this->addSql('DROP TABLE court_portal_event');
        $this->addSql('DROP TABLE creditor');
        $this->addSql('DROP TABLE debtor');
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE interest_rate_config');
        $this->addSql('DROP TABLE invoice');
        $this->addSql('DROP TABLE legal_case');
        $this->addSql('DROP TABLE legal_deadline');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE plan');
        $this->addSql('DROP TABLE reset_password_request');
        $this->addSql('DROP TABLE subscription');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
