<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002111954 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add legal_case.contractual_penalty_cap_percent for contracts that cap penalties at a share of the sum';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD contractual_penalty_cap_percent NUMERIC(6, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP contractual_penalty_cap_percent');
    }
}
