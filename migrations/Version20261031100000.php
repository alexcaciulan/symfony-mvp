<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Records when the court file number became known on each case, the start of the stamp duty payment window';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD court_case_number_known_at DATETIME DEFAULT NULL');

        // Existing cases: the portal proposal the lawyer confirmed is the best record of
        // the moment, then the first registration of the case, which is when the number
        // was typed in by hand. Cases with neither keep NULL and fall back to the weekly
        // reminder, the pace they had so far.
        $this->addSql(<<<'SQL'
            UPDATE legal_case lc
            JOIN portal_case_match m ON m.legal_case_id = lc.id AND m.status = 'accepted' AND m.court_case_number = lc.court_case_number
            SET lc.court_case_number_known_at = COALESCE(m.decided_at, m.created_at)
            WHERE lc.court_case_number IS NOT NULL
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE legal_case lc
            JOIN (
                SELECT legal_case_id, MIN(created_at) AS first_at
                FROM case_status_history
                WHERE new_status = 'DOSAR_INREGISTRAT'
                GROUP BY legal_case_id
            ) h ON h.legal_case_id = lc.id
            SET lc.court_case_number_known_at = h.first_at
            WHERE lc.court_case_number IS NOT NULL AND lc.court_case_number_known_at IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP court_case_number_known_at');
    }
}
