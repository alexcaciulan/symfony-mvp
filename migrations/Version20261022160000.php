<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add filing_package and filing_package_part (unsigned court filing files and their later signed versions)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE filing_package (id INT AUTO_INCREMENT NOT NULL, version INT NOT NULL, status VARCHAR(30) NOT NULL, opis_placement VARCHAR(30) NOT NULL, source_fingerprint CHAR(64) NOT NULL, source_manifest JSON NOT NULL, includes_stamp_duty_proof TINYINT NOT NULL, size_plan JSON NOT NULL, stale_reason VARCHAR(100) DEFAULT NULL, built_at DATETIME NOT NULL, total_unsigned_bytes INT NOT NULL, total_signed_bytes INT DEFAULT NULL, legal_case_id INT NOT NULL, built_by_id INT NOT NULL, previous_version_id INT DEFAULT NULL, INDEX IDX_122D65F582B4A9B (legal_case_id), INDEX IDX_122D65F58DE1CBC1 (built_by_id), INDEX IDX_122D65F53CBC65BC (previous_version_id), UNIQUE INDEX uniq_filing_package_case_version (legal_case_id, version), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE filing_package_part (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(30) NOT NULL, position INT NOT NULL, volume INT DEFAULT NULL, volume_count INT DEFAULT NULL, title VARCHAR(255) NOT NULL, page_count INT NOT NULL, annex_page_from INT DEFAULT NULL, annex_page_to INT DEFAULT NULL, source_document_ids JSON NOT NULL, part_token CHAR(32) NOT NULL, unsigned_path VARCHAR(255) NOT NULL, unsigned_sha256 CHAR(64) NOT NULL, unsigned_bytes INT NOT NULL, compression_applied TINYINT NOT NULL, signed_path VARCHAR(255) DEFAULT NULL, signed_sha256 CHAR(64) DEFAULT NULL, signed_bytes INT DEFAULT NULL, signed_uploaded_at DATETIME DEFAULT NULL, signature_status VARCHAR(30) NOT NULL, probable_match_confirmed_at DATETIME DEFAULT NULL, signature_report JSON DEFAULT NULL, package_id INT NOT NULL, signed_uploaded_by_id INT DEFAULT NULL, probable_match_confirmed_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_D0677DA1CC44F6D8 (part_token), INDEX IDX_D0677DA1F44CABFF (package_id), INDEX IDX_D0677DA1D91840E8 (signed_uploaded_by_id), INDEX IDX_D0677DA1F9A6B684 (probable_match_confirmed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE filing_package ADD CONSTRAINT FK_122D65F582B4A9B FOREIGN KEY (legal_case_id) REFERENCES legal_case (id)');
        $this->addSql('ALTER TABLE filing_package ADD CONSTRAINT FK_122D65F58DE1CBC1 FOREIGN KEY (built_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE filing_package ADD CONSTRAINT FK_122D65F53CBC65BC FOREIGN KEY (previous_version_id) REFERENCES filing_package (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE filing_package_part ADD CONSTRAINT FK_D0677DA1F44CABFF FOREIGN KEY (package_id) REFERENCES filing_package (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE filing_package_part ADD CONSTRAINT FK_D0677DA1D91840E8 FOREIGN KEY (signed_uploaded_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE filing_package_part ADD CONSTRAINT FK_D0677DA1F9A6B684 FOREIGN KEY (probable_match_confirmed_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE filing_package_part DROP FOREIGN KEY FK_D0677DA1F44CABFF');
        $this->addSql('ALTER TABLE filing_package_part DROP FOREIGN KEY FK_D0677DA1D91840E8');
        $this->addSql('ALTER TABLE filing_package_part DROP FOREIGN KEY FK_D0677DA1F9A6B684');
        $this->addSql('ALTER TABLE filing_package DROP FOREIGN KEY FK_122D65F582B4A9B');
        $this->addSql('ALTER TABLE filing_package DROP FOREIGN KEY FK_122D65F58DE1CBC1');
        $this->addSql('ALTER TABLE filing_package DROP FOREIGN KEY FK_122D65F53CBC65BC');
        $this->addSql('DROP TABLE filing_package_part');
        $this->addSql('DROP TABLE filing_package');
    }
}
