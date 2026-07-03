<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * R1 workflow revision: remap legacy "insolvent" closure to "closed without
 * recovery". The OP procedure no longer closes as insolvent (insolvency is an
 * enforcement-phase outcome). Data-only migration: CaseStatus / CaseTransition
 * are stored as plain strings (EnumMarkingStore), so no DDL is involved.
 */
final class Version20260630090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remap legacy INCHIS_PARTIAL_INSOLVABIL to INCHIS_FARA_RECUPERARE on legal_case + case_status_history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE legal_case SET status = 'INCHIS_FARA_RECUPERARE' WHERE status = 'INCHIS_PARTIAL_INSOLVABIL'");
        $this->addSql("UPDATE case_status_history SET new_status = 'INCHIS_FARA_RECUPERARE' WHERE new_status = 'INCHIS_PARTIAL_INSOLVABIL'");
        $this->addSql("UPDATE case_status_history SET old_status = 'INCHIS_FARA_RECUPERARE' WHERE old_status = 'INCHIS_PARTIAL_INSOLVABIL'");
        // Keep the audit log internally consistent with the renamed transition.
        $this->addSql("UPDATE audit_log SET new_data = JSON_SET(new_data, '$.transition', 'inchide_fara_recuperare') WHERE JSON_UNQUOTE(JSON_EXTRACT(new_data, '$.transition')) = 'inchide_insolvabil'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE legal_case SET status = 'INCHIS_PARTIAL_INSOLVABIL' WHERE status = 'INCHIS_FARA_RECUPERARE'");
        $this->addSql("UPDATE case_status_history SET new_status = 'INCHIS_PARTIAL_INSOLVABIL' WHERE new_status = 'INCHIS_FARA_RECUPERARE'");
        $this->addSql("UPDATE case_status_history SET old_status = 'INCHIS_PARTIAL_INSOLVABIL' WHERE old_status = 'INCHIS_FARA_RECUPERARE'");
        $this->addSql("UPDATE audit_log SET new_data = JSON_SET(new_data, '$.transition', 'inchide_insolvabil') WHERE JSON_UNQUOTE(JSON_EXTRACT(new_data, '$.transition')) = 'inchide_fara_recuperare'");
    }
}
