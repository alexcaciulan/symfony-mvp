<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002132125 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add legal_case.accessory_cutoff_date: the date the lawyer chose to compute the accessories up to';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD accessory_cutoff_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP accessory_cutoff_date');
    }
}
