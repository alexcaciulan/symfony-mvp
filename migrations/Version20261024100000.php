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
        // A registry payment not followed by a proof-based payment is still the current one.
        $this->addSql(<<<'SQL'
            UPDATE legal_case lc
            SET lc.stamp_duty_paid_through_registry = 1
            WHERE lc.stamp_duty_status = 'ACHITATA'
              AND EXISTS (
                  SELECT 1 FROM audit_log r
                  WHERE r.action = 'stamp_duty_paid_through_registry'
                    AND CAST(r.entity_id AS UNSIGNED) = lc.id
                    AND NOT EXISTS (
                        SELECT 1 FROM audit_log p
                        WHERE p.action = 'stamp_duty_paid'
                          AND p.entity_id = r.entity_id
                          AND p.created_at > r.created_at
                    )
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP stamp_duty_paid_through_registry');
    }
}
