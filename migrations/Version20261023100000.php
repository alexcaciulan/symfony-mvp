<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Court address reports for the admin (lawyer reports and delivery reports) and the requeue counter of inbound mail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE court_email_address_report (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, reason VARCHAR(30) NOT NULL, enhanced_status VARCHAR(10) DEFAULT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, resolution VARCHAR(20) DEFAULT NULL, address_id INT NOT NULL, legal_case_id INT DEFAULT NULL, reported_by_id INT DEFAULT NULL, inbound_mail_id INT DEFAULT NULL, submission_id INT DEFAULT NULL, resolved_by_id INT DEFAULT NULL, INDEX IDX_37AE33ECF5B7AF75 (address_id), INDEX IDX_37AE33EC82B4A9B (legal_case_id), INDEX IDX_37AE33EC71CE806 (reported_by_id), INDEX IDX_37AE33EC8795C61 (inbound_mail_id), INDEX IDX_37AE33ECE1FD4933 (submission_id), INDEX IDX_37AE33EC6713A32B (resolved_by_id), INDEX idx_court_email_report_open (address_id, resolved_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE court_email_address_report ADD CONSTRAINT FK_37AE33ECF5B7AF75 FOREIGN KEY (address_id) REFERENCES court_email_address (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE court_email_address_report ADD CONSTRAINT FK_37AE33EC82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE court_email_address_report ADD CONSTRAINT FK_37AE33EC71CE806 FOREIGN KEY (reported_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE court_email_address_report ADD CONSTRAINT FK_37AE33EC8795C61 FOREIGN KEY (inbound_mail_id) REFERENCES inbound_mail (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE court_email_address_report ADD CONSTRAINT FK_37AE33ECE1FD4933 FOREIGN KEY (submission_id) REFERENCES filing_submission (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE court_email_address_report ADD CONSTRAINT FK_37AE33EC6713A32B FOREIGN KEY (resolved_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inbound_mail ADD requeue_count INT DEFAULT 0 NOT NULL, ADD requeued_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbound_mail DROP requeue_count, DROP requeued_at');
        $this->addSql('DROP TABLE court_email_address_report');
    }
}
