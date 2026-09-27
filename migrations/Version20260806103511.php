<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Separates "the petition was generated" from "the petition was filed".
 *
 * Until now `depune_cerere` fired when the PDF was produced, so every case that
 * had a package read as filed. The backfill moves those cases to the new place:
 * the platform never observed a filing, and the absence of a court case number is
 * the only evidence available that nothing reached the registry yet. Cases already
 * registered with the court keep their status, since a dosar number proves arrival.
 */
final class Version20260806103511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add filing declaration fields and split CERERE_GENERATA from CERERE_DEPUSA';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD filed_at DATE DEFAULT NULL, ADD filing_channel VARCHAR(20) DEFAULT NULL, ADD filing_reference VARCHAR(100) DEFAULT NULL');

        $this->addSql("UPDATE legal_case SET status = 'CERERE_GENERATA' WHERE status = 'CERERE_DEPUSA' AND court_case_number IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE legal_case SET status = 'CERERE_DEPUSA' WHERE status = 'CERERE_GENERATA'");

        $this->addSql('ALTER TABLE legal_case DROP filed_at, DROP filing_channel, DROP filing_reference');
    }
}
