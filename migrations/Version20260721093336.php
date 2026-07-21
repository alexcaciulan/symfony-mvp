<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * New accounts default to MAX_ACCURACY extraction. Only the column default changes;
 * existing rows keep their current value.
 */
final class Version20260721093336 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Default extraction_mode to MAX_ACCURACY for new users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user CHANGE extraction_mode extraction_mode VARCHAR(20) DEFAULT \'MAX_ACCURACY\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user CHANGE extraction_mode extraction_mode VARCHAR(20) DEFAULT \'LOCAL_ONLY\' NOT NULL');
    }
}
