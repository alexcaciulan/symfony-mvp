<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Date the enforcement request was filed with the bailiff. The enforcement-limitation
 * term is closed against this date rather than against the case being in enforcement,
 * because the interruption of CPC art. 708 para. 1 pt. 2 attaches to the filing.
 *
 * Nullable and left null on existing rows: cases that entered enforcement before this
 * column existed carry no such record, and the term stays open for them, which is the
 * safe direction.
 */
final class Version20260805220504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the date the enforcement request was filed with the bailiff on legal_case.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD enforcement_request_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP enforcement_request_date');
    }
}
