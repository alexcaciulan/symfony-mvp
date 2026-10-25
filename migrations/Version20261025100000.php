<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keeps the reason of the last refused signed upload on each filing package part';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE filing_package_part ADD last_refusal_key VARCHAR(80) DEFAULT NULL, ADD last_refusal_params JSON DEFAULT NULL, ADD last_refusal_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE filing_package_part DROP last_refusal_key, DROP last_refusal_params, DROP last_refusal_at');
    }
}
