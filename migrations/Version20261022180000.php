<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add filing_submission, filing_email_part (with its package parts) and filing_attempt for transmitting the signed package to the court by email';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE filing_attempt (id INT AUTO_INCREMENT NOT NULL, attempt_no INT NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, outcome VARCHAR(20) DEFAULT NULL, smtp_code INT DEFAULT NULL, enhanced_status VARCHAR(20) DEFAULT NULL, error_class VARCHAR(255) DEFAULT NULL, message VARCHAR(500) DEFAULT NULL, source VARCHAR(30) NOT NULL, next_retry_at DATETIME DEFAULT NULL, part_id INT NOT NULL, INDEX IDX_D4A900064CE34BEC (part_id), UNIQUE INDEX uniq_filing_attempt_part_no (part_id, attempt_no), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE filing_email_part (id INT AUTO_INCREMENT NOT NULL, sequence INT NOT NULL, total INT NOT NULL, subject VARCHAR(255) NOT NULL, message_id VARCHAR(255) NOT NULL, in_reply_to VARCHAR(255) DEFAULT NULL, references_header VARCHAR(255) DEFAULT NULL, verp_token VARCHAR(32) NOT NULL, encoded_bytes INT DEFAULT NULL, status VARCHAR(30) NOT NULL, attempt_count INT NOT NULL, sent_at DATETIME DEFAULT NULL, eml_path VARCHAR(255) DEFAULT NULL, eml_sha256 CHAR(64) DEFAULT NULL, last_smtp_code INT DEFAULT NULL, last_enhanced_status VARCHAR(20) DEFAULT NULL, last_error VARCHAR(500) DEFAULT NULL, submission_id INT NOT NULL, UNIQUE INDEX UNIQ_AA6E4C3268F9F380 (verp_token), INDEX IDX_AA6E4C32E1FD4933 (submission_id), UNIQUE INDEX uniq_filing_email_part_sequence (submission_id, sequence), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE filing_email_part_package_part (email_part_id INT NOT NULL, package_part_id INT NOT NULL, INDEX IDX_468DF294F1232323 (email_part_id), INDEX IDX_468DF294C3B82E60 (package_part_id), PRIMARY KEY (email_part_id, package_part_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE filing_submission (id INT AUTO_INCREMENT NOT NULL, to_address VARCHAR(254) NOT NULL, intended_to_address VARCHAR(254) NOT NULL, from_address VARCHAR(254) NOT NULL, cc_address VARCHAR(254) NOT NULL, reply_to_address VARCHAR(254) NOT NULL, envelope_sender VARCHAR(254) NOT NULL, token CHAR(26) NOT NULL, delivery_status VARCHAR(40) NOT NULL, evidence_status VARCHAR(30) NOT NULL, active_lock SMALLINT DEFAULT NULL, confirmations JSON NOT NULL, address_state_snapshot JSON NOT NULL, initiated_at DATETIME NOT NULL, initiated_ip VARCHAR(45) DEFAULT NULL, initiated_session_hash CHAR(64) DEFAULT NULL, sent_at DATETIME DEFAULT NULL, court_receipt_at DATETIME DEFAULT NULL, read_receipt_at DATETIME DEFAULT NULL, template_version VARCHAR(40) NOT NULL, review_reason VARCHAR(120) DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, legal_case_id INT NOT NULL, package_id INT NOT NULL, court_id INT NOT NULL, court_email_address_id INT DEFAULT NULL, initiated_by_id INT NOT NULL, UNIQUE INDEX UNIQ_A75246825F37A13B (token), INDEX IDX_A752468282B4A9B (legal_case_id), INDEX IDX_A7524682F44CABFF (package_id), INDEX IDX_A7524682E3184009 (court_id), INDEX IDX_A7524682EF64FCAE (court_email_address_id), INDEX IDX_A7524682C4EF1FC7 (initiated_by_id), UNIQUE INDEX uniq_filing_submission_active (legal_case_id, active_lock), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE filing_attempt ADD CONSTRAINT FK_D4A900064CE34BEC FOREIGN KEY (part_id) REFERENCES filing_email_part (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE filing_email_part ADD CONSTRAINT FK_AA6E4C32E1FD4933 FOREIGN KEY (submission_id) REFERENCES filing_submission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE filing_email_part_package_part ADD CONSTRAINT FK_468DF294F1232323 FOREIGN KEY (email_part_id) REFERENCES filing_email_part (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE filing_email_part_package_part ADD CONSTRAINT FK_468DF294C3B82E60 FOREIGN KEY (package_part_id) REFERENCES filing_package_part (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE filing_submission ADD CONSTRAINT FK_A752468282B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE filing_submission ADD CONSTRAINT FK_A7524682F44CABFF FOREIGN KEY (package_id) REFERENCES filing_package (id)');
        $this->addSql('ALTER TABLE filing_submission ADD CONSTRAINT FK_A7524682E3184009 FOREIGN KEY (court_id) REFERENCES court (id)');
        $this->addSql('ALTER TABLE filing_submission ADD CONSTRAINT FK_A7524682EF64FCAE FOREIGN KEY (court_email_address_id) REFERENCES court_email_address (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE filing_submission ADD CONSTRAINT FK_A7524682C4EF1FC7 FOREIGN KEY (initiated_by_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE filing_attempt DROP FOREIGN KEY FK_D4A900064CE34BEC');
        $this->addSql('ALTER TABLE filing_email_part DROP FOREIGN KEY FK_AA6E4C32E1FD4933');
        $this->addSql('ALTER TABLE filing_email_part_package_part DROP FOREIGN KEY FK_468DF294F1232323');
        $this->addSql('ALTER TABLE filing_email_part_package_part DROP FOREIGN KEY FK_468DF294C3B82E60');
        $this->addSql('ALTER TABLE filing_submission DROP FOREIGN KEY FK_A752468282B4A9B');
        $this->addSql('ALTER TABLE filing_submission DROP FOREIGN KEY FK_A7524682F44CABFF');
        $this->addSql('ALTER TABLE filing_submission DROP FOREIGN KEY FK_A7524682E3184009');
        $this->addSql('ALTER TABLE filing_submission DROP FOREIGN KEY FK_A7524682EF64FCAE');
        $this->addSql('ALTER TABLE filing_submission DROP FOREIGN KEY FK_A7524682C4EF1FC7');
        $this->addSql('DROP TABLE filing_attempt');
        $this->addSql('DROP TABLE filing_email_part_package_part');
        $this->addSql('DROP TABLE filing_email_part');
        $this->addSql('DROP TABLE filing_submission');
    }
}
