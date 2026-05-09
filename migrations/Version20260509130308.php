<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add Court.coveredLocalities (JSON nullable) — territorial jurisdiction range.
 *
 * Support for CompetentCourtResolver (Pas 2.3): when claim ≤ 200,000 RON,
 * matches debtor locality against the competent local court (CPC art. 94 + 107).
 * Data populated subsequently from data/courts.json via `app:import-courts --update`.
 */
final class Version20260509130308 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add court.covered_localities JSON column (Pas 2.3 — CompetentCourtResolver).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE court ADD covered_localities JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE court DROP covered_localities');
    }
}
