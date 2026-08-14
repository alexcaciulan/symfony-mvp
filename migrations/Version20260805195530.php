<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Communication date of the ruling given on an annulment request, plus the two
 * long-range alert tiers of the limitation-type deadlines.
 *
 * The date is the anchor of the enforcement limitation when the debtor challenged the
 * order: the order becomes final through the rejection of that request (CPC art. 1024
 * para. 8), and the three years run from the communication of that ruling (CPC art.
 * 705 para. 2). Nullable and left empty on existing rows: it is not derivable from
 * anything stored, so the lawyer records it and the case is listed as a blockage
 * meanwhile.
 *
 * The two alert flags default to 0 on existing rows, which is the correct state: a
 * long-range reminder has never been sent for them, so the next cron run may still
 * send one if the term is inside its window.
 */
final class Version20260805195530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Annulment-ruling communication date on legal_case, long-range alert tiers on legal_deadline';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD annulment_ruling_communication_date DATE DEFAULT NULL');
        // Added with a default so the existing rows get a value, then the default is
        // dropped to match the mapping, which declares no default (the entity does).
        $this->addSql('ALTER TABLE legal_deadline ADD alert_sent_long_range TINYINT NOT NULL DEFAULT 0, ADD alert_sent_mid_range TINYINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE legal_deadline ALTER COLUMN alert_sent_long_range DROP DEFAULT');
        $this->addSql('ALTER TABLE legal_deadline ALTER COLUMN alert_sent_mid_range DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP annulment_ruling_communication_date');
        $this->addSql('ALTER TABLE legal_deadline DROP alert_sent_long_range, DROP alert_sent_mid_range');
    }
}
