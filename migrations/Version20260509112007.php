<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename legacy "contestație" values to "cerere în anulare" (CPC art. 1024).
 * Aligns DB with enum/workflow rename C2 from 2026-05-09 legal review.
 *
 * Affected columns (all VARCHAR(30) with BackedEnum string values):
 *   - legal_case.status            (CaseStatus)
 *   - legal_deadline.type          (DeadlineType)
 *   - case_status_history.old_status, new_status  (CaseStatus snapshots
 *     consumed by UI history rendering — must stay decodable via tryFrom)
 *
 * audit_log.old_data / new_data are JSON snapshots reflecting the literal
 * payload at the moment of each historical event; they are intentionally
 * NOT updated. Workflow transition names ('contesta', etc.) live only in
 * Symfony Workflow audit logs (not persisted in DB).
 */
final class Version20260509112007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename CONTESTATA/CONTESTATIE values to IN_ANULARE/CERERE_IN_ANULARE (CPC art. 1024)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE legal_case SET status = 'IN_ANULARE' WHERE status = 'CONTESTATA'");
        $this->addSql("UPDATE legal_deadline SET type = 'CERERE_IN_ANULARE' WHERE type = 'CONTESTATIE'");
        $this->addSql("UPDATE case_status_history SET old_status = 'IN_ANULARE' WHERE old_status = 'CONTESTATA'");
        $this->addSql("UPDATE case_status_history SET new_status = 'IN_ANULARE' WHERE new_status = 'CONTESTATA'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE legal_case SET status = 'CONTESTATA' WHERE status = 'IN_ANULARE'");
        $this->addSql("UPDATE legal_deadline SET type = 'CONTESTATIE' WHERE type = 'CERERE_IN_ANULARE'");
        $this->addSql("UPDATE case_status_history SET old_status = 'CONTESTATA' WHERE old_status = 'IN_ANULARE'");
        $this->addSql("UPDATE case_status_history SET new_status = 'CONTESTATA' WHERE new_status = 'IN_ANULARE'");
    }
}
