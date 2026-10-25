<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marks stamp duty paid through the electronic registry, backfilled from the registry payment audit entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD stamp_duty_paid_through_registry TINYINT DEFAULT 0 NOT NULL');
        // A registry payment is only possible on an unpaid duty, and a receipt filed
        // afterwards documents it (the service keeps the flag then), so any registry
        // payment entry on a paid case marks it.
        $this->addSql(<<<'SQL'
            UPDATE legal_case lc
            SET lc.stamp_duty_paid_through_registry = 1
            WHERE lc.stamp_duty_status = 'ACHITATA'
              AND EXISTS (
                  SELECT 1 FROM audit_log r
                  WHERE r.action = 'stamp_duty_paid_through_registry'
                    AND r.entity_type = 'App\\Entity\\LegalCase'
                    AND CAST(r.entity_id AS UNSIGNED) = lc.id
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP stamp_duty_paid_through_registry');
    }
}
