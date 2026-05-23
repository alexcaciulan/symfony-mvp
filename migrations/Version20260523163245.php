<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pas 6.1 — LegalCase.portalMonitoringActive (bool, DEFAULT 0): flag de activare
 * a monitorizării zilnice portal.just.ro. DEFAULT 0 explicit (pattern ca
 * `debtor.in_insolvency`) pentru rândurile existente; monitorizarea se activează
 * explicit din tab-ul „Activitate Portal".
 */
final class Version20260523163245 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pas 6.1: LegalCase.portalMonitoringActive flag';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD portal_monitoring_active TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP portal_monitoring_active');
    }
}
