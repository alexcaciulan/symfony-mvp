<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008145250 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Court case number found on portal.just.ro, pending the lawyer\'s confirmation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD portal_proposed_number VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP portal_proposed_number');
    }
}
