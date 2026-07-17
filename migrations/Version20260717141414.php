<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260717141414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable security_stamp to user for lazy session revocation (audit #8).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD security_stamp VARCHAR(32) DEFAULT NULL');
        // Backfill existing rows with a per-row random value. Column stays nullable so live
        // sessions serialized before this field keep working (they carry no stamp and
        // User::isEqualTo skips the stamp check for them) instead of a forced deploy logout.
        $this->addSql("UPDATE user SET security_stamp = SUBSTRING(REPLACE(UUID(), '-', ''), 1, 32) WHERE security_stamp IS NULL");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user DROP security_stamp');
    }
}
