<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009120159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portal case proposals move from legal_case to portal_case_match, which also keeps the numbers the lawyer set aside';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE portal_case_match (id INT AUTO_INCREMENT NOT NULL, court_case_number VARCHAR(50) NOT NULL, status VARCHAR(20) NOT NULL, source VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, legal_case_id INT NOT NULL, decided_by_id INT DEFAULT NULL, INDEX IDX_99AADE3F82B4A9B (legal_case_id), INDEX IDX_99AADE3FE26B496B (decided_by_id), UNIQUE INDEX uniq_portal_case_match_number (legal_case_id, court_case_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE portal_case_match ADD CONSTRAINT FK_99AADE3F82B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portal_case_match ADD CONSTRAINT FK_99AADE3FE26B496B FOREIGN KEY (decided_by_id) REFERENCES user (id) ON DELETE SET NULL');
        // A proposal still waiting keeps waiting; who found it is not recorded, the daily search is the likelier.
        $this->addSql("INSERT INTO portal_case_match (legal_case_id, court_case_number, status, source, created_at) SELECT id, portal_proposed_number, 'proposed', 'auto', NOW() FROM legal_case WHERE portal_proposed_number IS NOT NULL AND court_case_number IS NULL");
        $this->addSql('ALTER TABLE legal_case DROP portal_proposed_number');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD portal_proposed_number VARCHAR(50) DEFAULT NULL');
        $this->addSql("UPDATE legal_case lc JOIN portal_case_match m ON m.legal_case_id = lc.id AND m.status = 'proposed' SET lc.portal_proposed_number = m.court_case_number");
        $this->addSql('ALTER TABLE portal_case_match DROP FOREIGN KEY FK_99AADE3F82B4A9B');
        $this->addSql('ALTER TABLE portal_case_match DROP FOREIGN KEY FK_99AADE3FE26B496B');
        $this->addSql('DROP TABLE portal_case_match');
    }
}
