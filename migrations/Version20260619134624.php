<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619134624 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create county + city nomenclature tables (UAT level) with 1-N relation. Court is not touched.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE city (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, normalized_name VARCHAR(150) NOT NULL, type VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, county_id INT NOT NULL, INDEX IDX_2D5B023485E73F45 (county_id), INDEX idx_city_normalized (normalized_name), UNIQUE INDEX uniq_city_county_normalized (county_id, normalized_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE county (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, normalized_name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_58E2FF255E237E06 (name), UNIQUE INDEX UNIQ_58E2FF25D69C0128 (normalized_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE city ADD CONSTRAINT FK_2D5B023485E73F45 FOREIGN KEY (county_id) REFERENCES county (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE city DROP FOREIGN KEY FK_2D5B023485E73F45');
        $this->addSql('DROP TABLE city');
        $this->addSql('DROP TABLE county');
    }
}
