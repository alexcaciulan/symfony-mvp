<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pas 2.5.1 — pre-condiții extracție: Document.extractionStrategy + LegalCase.extractionModeOverride + User.extractionMode (default LOCAL_ONLY per GDPR art. 25).
 */
final class Version20260509193449 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pas 2.5.1 — adaugă extraction_strategy (document), extraction_mode_override (legal_case), extraction_mode (user, default LOCAL_ONLY)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD extraction_strategy VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE legal_case ADD extraction_mode_override VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD extraction_mode VARCHAR(20) DEFAULT \'LOCAL_ONLY\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP extraction_strategy');
        $this->addSql('ALTER TABLE legal_case DROP extraction_mode_override');
        $this->addSql('ALTER TABLE user DROP extraction_mode');
    }
}
