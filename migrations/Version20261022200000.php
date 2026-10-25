<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inbound court mail (receipts, delivery reports, replies), the poll heartbeat, and their links from filing submissions and court email addresses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbound_mail (id INT AUTO_INCREMENT NOT NULL, source VARCHAR(20) NOT NULL, source_id VARCHAR(191) NOT NULL, message_id VARCHAR(255) DEFAULT NULL, in_reply_to VARCHAR(255) DEFAULT NULL, references_header LONGTEXT DEFAULT NULL, from_address VARCHAR(254) DEFAULT NULL, to_address VARCHAR(1000) DEFAULT NULL, cc_addresses JSON NOT NULL, delivered_to VARCHAR(254) DEFAULT NULL, subject VARCHAR(255) DEFAULT NULL, received_at DATETIME NOT NULL, raw_path VARCHAR(255) NOT NULL, raw_sha256 CHAR(64) NOT NULL, raw_bytes INT NOT NULL, raw_mime VARCHAR(10) NOT NULL, original_filename VARCHAR(255) DEFAULT NULL, auth_verdict VARCHAR(20) DEFAULT NULL, classification VARCHAR(30) DEFAULT NULL, dsn_status VARCHAR(20) DEFAULT NULL, dsn_action VARCHAR(20) DEFAULT NULL, dsn_final_recipient VARCHAR(254) DEFAULT NULL, dsn_diagnostic LONGTEXT DEFAULT NULL, match_method VARCHAR(20) DEFAULT NULL, match_confidence VARCHAR(20) DEFAULT NULL, review_status VARCHAR(20) NOT NULL, review_reason VARCHAR(120) DEFAULT NULL, processed_at DATETIME DEFAULT NULL, forwarded_at DATETIME DEFAULT NULL, purge_after DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, matched_part_id INT DEFAULT NULL, matched_submission_id INT DEFAULT NULL, legal_case_id INT DEFAULT NULL, uploaded_by_id INT DEFAULT NULL, INDEX IDX_FC3979BEDC6FEFA4 (matched_part_id), INDEX IDX_FC3979BEDDCD4917 (matched_submission_id), INDEX IDX_FC3979BE82B4A9B (legal_case_id), INDEX IDX_FC3979BEA2B28FE8 (uploaded_by_id), INDEX idx_inbound_mail_message_id (message_id), INDEX idx_inbound_mail_sha (raw_sha256), INDEX idx_inbound_mail_purge (classification, purge_after), INDEX idx_inbound_mail_review (review_status), UNIQUE INDEX uniq_inbound_mail_source (source, source_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE inbound_poll_heartbeat (id INT AUTO_INCREMENT NOT NULL, last_attempt_at DATETIME DEFAULT NULL, last_success_at DATETIME DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, last_fetched_count INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE inbound_mail ADD CONSTRAINT FK_FC3979BEDC6FEFA4 FOREIGN KEY (matched_part_id) REFERENCES filing_email_part (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inbound_mail ADD CONSTRAINT FK_FC3979BEDDCD4917 FOREIGN KEY (matched_submission_id) REFERENCES filing_submission (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inbound_mail ADD CONSTRAINT FK_FC3979BE82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inbound_mail ADD CONSTRAINT FK_FC3979BEA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        // SET NULL on both sides: these links are circular with inbound_mail.matched_submission_id.
        $this->addSql('ALTER TABLE filing_submission ADD receipt_inbound_mail_id INT DEFAULT NULL, ADD read_receipt_inbound_mail_id INT DEFAULT NULL, ADD review_return_status VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE filing_submission ADD CONSTRAINT FK_A75246827E886288 FOREIGN KEY (receipt_inbound_mail_id) REFERENCES inbound_mail (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE filing_submission ADD CONSTRAINT FK_A75246823825130E FOREIGN KEY (read_receipt_inbound_mail_id) REFERENCES inbound_mail (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A75246827E886288 ON filing_submission (receipt_inbound_mail_id)');
        $this->addSql('CREATE INDEX IDX_A75246823825130E ON filing_submission (read_receipt_inbound_mail_id)');
        $this->addSql('ALTER TABLE court_email_address ADD invalidated_by_inbound_mail_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE court_email_address ADD CONSTRAINT FK_89FF8C2F97D6BC12 FOREIGN KEY (invalidated_by_inbound_mail_id) REFERENCES inbound_mail (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_89FF8C2F97D6BC12 ON court_email_address (invalidated_by_inbound_mail_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE court_email_address DROP FOREIGN KEY FK_89FF8C2F97D6BC12');
        $this->addSql('DROP INDEX IDX_89FF8C2F97D6BC12 ON court_email_address');
        $this->addSql('ALTER TABLE court_email_address DROP invalidated_by_inbound_mail_id');
        $this->addSql('ALTER TABLE filing_submission DROP FOREIGN KEY FK_A75246827E886288');
        $this->addSql('ALTER TABLE filing_submission DROP FOREIGN KEY FK_A75246823825130E');
        $this->addSql('DROP INDEX IDX_A75246827E886288 ON filing_submission');
        $this->addSql('DROP INDEX IDX_A75246823825130E ON filing_submission');
        $this->addSql('ALTER TABLE filing_submission DROP receipt_inbound_mail_id, DROP read_receipt_inbound_mail_id, DROP review_return_status');
        $this->addSql('ALTER TABLE inbound_mail DROP FOREIGN KEY FK_FC3979BEDC6FEFA4');
        $this->addSql('ALTER TABLE inbound_mail DROP FOREIGN KEY FK_FC3979BEDDCD4917');
        $this->addSql('ALTER TABLE inbound_mail DROP FOREIGN KEY FK_FC3979BE82B4A9B');
        $this->addSql('ALTER TABLE inbound_mail DROP FOREIGN KEY FK_FC3979BEA2B28FE8');
        $this->addSql('DROP TABLE inbound_poll_heartbeat');
        $this->addSql('DROP TABLE inbound_mail');
    }
}
