<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510220717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pas 3.0 wizard — relax document.legal_case_id to NULL so files can be uploaded BEFORE the case exists (wizard step 0). On submit Step 4 the FK is set to the newly created LegalCase.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document CHANGE legal_case_id legal_case_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Down assumes all existing rows have legal_case_id populated. If wizard pending
        // uploads exist (legal_case_id IS NULL), the down() will fail — that's expected;
        // we don't support rolling back this schema while user wizards are in flight.
        $this->addSql('ALTER TABLE document CHANGE legal_case_id legal_case_id INT NOT NULL');
    }
}
