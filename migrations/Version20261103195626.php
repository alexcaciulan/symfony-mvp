<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103195626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keeps why a claim position was excluded and marks a generated act as outdated once the claim it rests on changed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE claim_item ADD exclusion_reason VARCHAR(30) DEFAULT NULL, ADD exclusion_note VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD outdated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE claim_item DROP exclusion_reason, DROP exclusion_note');
        $this->addSql('ALTER TABLE document DROP outdated_at');
    }
}
