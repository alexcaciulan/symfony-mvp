<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rewire Court to the county/city nomenclature: county string -> FK county,
 * coveredLocalities JSON -> ManyToMany city (court_covered_city).
 *
 * Operational note: app:import-cities must run before this migration if the
 * court table already holds data (the county_id backfill matches on county
 * name; without seeded counties it stays NULL and the NOT NULL step fails).
 * On a fresh/drop-recreate DB the court table is empty, so the backfill is a
 * no-op and NOT NULL is safe. After this migration, run app:import-courts
 * --update to repopulate covered_city coverage (courts.json is canonical;
 * the JSON->join backfill is not done in SQL).
 */
final class Version20260619141800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rewire Court.county to FK county and coveredLocalities JSON to ManyToMany city';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE court_covered_city (court_id INT NOT NULL, city_id INT NOT NULL, INDEX IDX_6A6CB56FE3184009 (court_id), INDEX IDX_6A6CB56F8BAC62AF (city_id), PRIMARY KEY (court_id, city_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE court_covered_city ADD CONSTRAINT FK_6A6CB56FE3184009 FOREIGN KEY (court_id) REFERENCES court (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE court_covered_city ADD CONSTRAINT FK_6A6CB56F8BAC62AF FOREIGN KEY (city_id) REFERENCES city (id) ON DELETE CASCADE');

        // Add FK column as nullable, backfill from the existing county name, then enforce NOT NULL.
        $this->addSql('ALTER TABLE court ADD county_id INT DEFAULT NULL');
        $this->addSql('UPDATE court c JOIN county co ON c.county = co.name SET c.county_id = co.id');
        $this->addSql('ALTER TABLE court ADD CONSTRAINT FK_63AE193F85E73F45 FOREIGN KEY (county_id) REFERENCES county (id)');
        $this->addSql('CREATE INDEX IDX_63AE193F85E73F45 ON court (county_id)');
        $this->addSql('ALTER TABLE court MODIFY county_id INT NOT NULL');

        // Drop the legacy free-text county and JSON coverage columns.
        $this->addSql('ALTER TABLE court DROP county, DROP covered_localities');
    }

    public function down(Schema $schema): void
    {
        // covered_localities is recreated empty: the JSON coverage cannot be
        // rebuilt from court_covered_city here (re-run app:import-courts to restore).
        $this->addSql('ALTER TABLE court ADD county VARCHAR(50) DEFAULT NULL, ADD covered_localities JSON DEFAULT NULL');
        $this->addSql('UPDATE court c JOIN county co ON c.county_id = co.id SET c.county = co.name');
        $this->addSql('ALTER TABLE court MODIFY county VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE court DROP FOREIGN KEY FK_63AE193F85E73F45');
        $this->addSql('DROP INDEX IDX_63AE193F85E73F45 ON court');
        $this->addSql('ALTER TABLE court DROP county_id');

        $this->addSql('ALTER TABLE court_covered_city DROP FOREIGN KEY FK_6A6CB56FE3184009');
        $this->addSql('ALTER TABLE court_covered_city DROP FOREIGN KEY FK_6A6CB56F8BAC62AF');
        $this->addSql('DROP TABLE court_covered_city');
    }
}
