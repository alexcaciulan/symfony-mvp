<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds legal_case.claim_description: the object of the claim in the lawyer's own
 * words. With several invoices this is a summary naming all of them, distinct
 * from the per-invoice wording carried on each ClaimItem.
 */
final class Version20260722121604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add legal_case.claim_description for the whole-claim object text';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD claim_description LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP claim_description');
    }
}
