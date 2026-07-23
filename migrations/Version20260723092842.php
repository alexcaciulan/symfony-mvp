<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widens the reference columns AI extraction can fill with a longer descriptive
 * phrase than they held: legal_case.contract_reference (100 -> 255) and
 * claim_item.cause_reference (150 -> 255). The latter matters beyond the 500 it
 * used to cause: cause_reference feeds causeKey(), which groups positions by
 * cause for material competence (CPC art. 99), so it must never be truncated
 * where two distinct causes could collide on a shared prefix.
 */
final class Version20260723092842 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen legal_case.contract_reference and claim_item.cause_reference to VARCHAR(255)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case CHANGE contract_reference contract_reference VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE claim_item CHANGE cause_reference cause_reference VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case CHANGE contract_reference contract_reference VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE claim_item CHANGE cause_reference cause_reference VARCHAR(150) DEFAULT NULL');
    }
}
