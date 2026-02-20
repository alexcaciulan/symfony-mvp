<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add phone column to user table (may already exist from develop branch).
 */
final class Version20260220102210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add phone column to user table';
    }

    public function up(Schema $schema): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns('user');
        if (!isset($columns['phone'])) {
            $this->addSql('ALTER TABLE user ADD phone VARCHAR(20) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP phone');
    }
}
