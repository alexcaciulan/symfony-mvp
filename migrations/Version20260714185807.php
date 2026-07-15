<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260714185807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stamp duty payment tracking on legal_case + structured registered-office location on creditor (drives the payment UAT).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor ADD address_county VARCHAR(100) DEFAULT NULL, ADD address_locality VARCHAR(150) DEFAULT NULL, ADD anaf_checked_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE legal_case ADD stamp_duty_status VARCHAR(30) DEFAULT \'NEACHITATA\' NOT NULL, ADD stamp_duty_paid_at DATE DEFAULT NULL, ADD stamp_duty_paid_amount NUMERIC(8, 2) DEFAULT NULL, ADD stamp_duty_payer_name VARCHAR(255) DEFAULT NULL, ADD stamp_duty_uat VARCHAR(150) DEFAULT NULL, ADD stamp_duty_payment_reference VARCHAR(100) DEFAULT NULL, ADD stamp_duty_law_version VARCHAR(120) DEFAULT NULL');

        // A case the court has actually registered was necessarily stamped outside the
        // platform, so leaving it on the NEACHITATA default would flag settled cases as
        // unpaid. CERERE_DEPUSA is deliberately NOT included: that status is applied when
        // the petition PDF is generated, not when it reaches the registry (see
        // docs/LexRecovery/ANALIZA-PLATA-TAXA-TIMBRU.md, "defectul semantic"), so such a
        // case may well be sitting unstamped on the lawyer's disk. Presuming it paid
        // would silently switch the new gate off exactly where it is most needed.
        $this->addSql("UPDATE legal_case SET stamp_duty_status = 'ACHITATA' WHERE status IN ('DOSAR_INREGISTRAT', 'TERMEN_FIXAT', 'ORDONANTA_EMISA', 'IN_ANULARE', 'DEFINITIVA', 'EXECUTARE', 'RESPINSA', 'INCHIS_SUCCES', 'INCHIS_FARA_RECUPERARE')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor DROP address_county, DROP address_locality, DROP anaf_checked_at');
        $this->addSql('ALTER TABLE legal_case DROP stamp_duty_status, DROP stamp_duty_paid_at, DROP stamp_duty_paid_amount, DROP stamp_duty_payer_name, DROP stamp_duty_uat, DROP stamp_duty_payment_reference, DROP stamp_duty_law_version');
    }
}
