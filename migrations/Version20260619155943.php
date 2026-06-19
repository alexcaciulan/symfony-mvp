<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add structured county + locality to debtor for competent-court resolution.
 */
final class Version20260619155943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add address_county + address_locality to debtor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE debtor ADD address_county VARCHAR(100) DEFAULT NULL, ADD address_locality VARCHAR(150) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE debtor DROP address_county, DROP address_locality');
    }
}
