<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add series, number and date of the power of attorney to document';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD power_of_attorney_series VARCHAR(20) DEFAULT NULL, ADD power_of_attorney_number VARCHAR(30) DEFAULT NULL, ADD power_of_attorney_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP power_of_attorney_series, DROP power_of_attorney_number, DROP power_of_attorney_date');
    }
}
