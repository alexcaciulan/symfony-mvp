<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Registration number the bailiff assigns to the enforcement request. It is what closes
 * the enforcement-limitation term (CPC art. 705 para. 1): the date the lawyer declares
 * only silences the alerts on that term, because an irreversible closing rests on a fact
 * confirmed from outside the platform.
 */
final class Version20260813160024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add legal_case.enforcement_registration_number (bailiff registration number of the enforcement request)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD enforcement_registration_number VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP enforcement_registration_number');
    }
}
