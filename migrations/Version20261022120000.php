<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the lawyer bar roll number and the "pay at filing" stamp duty state';
    }

    public function up(Schema $schema): void
    {
        // Paying at filing was an intention, the money never moved: such cases owe the duty.
        $this->addSql("UPDATE legal_case SET stamp_duty_status = 'NEACHITATA' WHERE stamp_duty_status = 'ACHITARE_LA_DEPUNERE'");
        $this->addSql('ALTER TABLE user DROP bar_number');
    }

    public function down(Schema $schema): void
    {
        // The stamp duty states cannot be told apart once merged; only the column comes back.
        $this->addSql('ALTER TABLE user ADD bar_number VARCHAR(50) DEFAULT NULL');
    }
}
