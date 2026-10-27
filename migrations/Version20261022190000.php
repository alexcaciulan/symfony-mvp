<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link a court email address marked invalid to the filing submission whose SMTP rejection caused it';
    }

    public function up(Schema $schema): void
    {
        // SET NULL: the link is circular with filing_submission.court_email_address_id.
        $this->addSql('ALTER TABLE court_email_address ADD invalidated_by_submission_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE court_email_address ADD CONSTRAINT FK_89FF8C2F3F5155A9 FOREIGN KEY (invalidated_by_submission_id) REFERENCES filing_submission (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_89FF8C2F3F5155A9 ON court_email_address (invalidated_by_submission_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE court_email_address DROP FOREIGN KEY FK_89FF8C2F3F5155A9');
        $this->addSql('DROP INDEX IDX_89FF8C2F3F5155A9 ON court_email_address');
        $this->addSql('ALTER TABLE court_email_address DROP invalidated_by_submission_id');
    }
}
