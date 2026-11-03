<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keeps unfinished wizard runs in case_draft, per user, and links the step 0 uploads to their draft';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE case_draft (id INT AUTO_INCREMENT NOT NULL, payload MEDIUMBLOB NOT NULL, payload_version INT NOT NULL, version INT NOT NULL, step_reached VARCHAR(20) NOT NULL, documents_count INT NOT NULL, creditor_name VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_activity_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_14956C78A76ED395 (user_id), INDEX idx_case_draft_last_activity (last_activity_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE case_draft ADD CONSTRAINT FK_14956C78A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document ADD draft_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76E2F3C5D1 FOREIGN KEY (draft_id) REFERENCES case_draft (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D8698A76E2F3C5D1 ON document (draft_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76E2F3C5D1');
        $this->addSql('DROP INDEX IDX_D8698A76E2F3C5D1 ON document');
        $this->addSql('ALTER TABLE document DROP draft_id');
        $this->addSql('ALTER TABLE case_draft DROP FOREIGN KEY FK_14956C78A76ED395');
        $this->addSql('DROP TABLE case_draft');
    }
}
