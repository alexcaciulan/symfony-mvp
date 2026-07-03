<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260630154513 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiscal invoicing (thin mirror): fiscal_invoice + fiscal_invoice_line + app_setting. Additive only.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fiscal_invoice (id INT AUTO_INCREMENT NOT NULL, series VARCHAR(10) DEFAULT NULL, number VARCHAR(20) DEFAULT NULL, currency VARCHAR(3) DEFAULT \'RON\' NOT NULL, issued_at DATETIME DEFAULT NULL, tax_point_date DATETIME DEFAULT NULL, due_at DATETIME DEFAULT NULL, kind VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, e_invoice_status VARCHAR(20) NOT NULL, provider_name VARCHAR(50) DEFAULT NULL, provider_invoice_id VARCHAR(255) DEFAULT NULL, spv_id VARCHAR(255) DEFAULT NULL, e_invoice_error LONGTEXT DEFAULT NULL, supplier JSON NOT NULL, buyer JSON NOT NULL, net_total NUMERIC(12, 2) NOT NULL, vat_total NUMERIC(12, 2) NOT NULL, gross_total NUMERIC(12, 2) NOT NULL, pdf_path VARCHAR(255) DEFAULT NULL, pdf_url VARCHAR(512) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, invoice_id INT DEFAULT NULL, user_id INT NOT NULL, storno_of_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_B35DFA7D2989F1FD (invoice_id), INDEX IDX_B35DFA7DA76ED395 (user_id), INDEX IDX_B35DFA7D412B6072 (storno_of_id), UNIQUE INDEX uniq_fiscal_invoice_series_number (series, number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fiscal_invoice_line (id INT AUTO_INCREMENT NOT NULL, description VARCHAR(255) NOT NULL, quantity NUMERIC(10, 3) NOT NULL, unit_price_net NUMERIC(12, 2) NOT NULL, vat_rate NUMERIC(5, 2) NOT NULL, line_net NUMERIC(12, 2) NOT NULL, line_vat NUMERIC(12, 2) NOT NULL, line_gross NUMERIC(12, 2) NOT NULL, fiscal_invoice_id INT NOT NULL, INDEX IDX_CF2456C8C1E11AFE (fiscal_invoice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE app_setting (name VARCHAR(64) NOT NULL, value LONGTEXT DEFAULT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fiscal_invoice ADD CONSTRAINT FK_B35DFA7D2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id)');
        $this->addSql('ALTER TABLE fiscal_invoice ADD CONSTRAINT FK_B35DFA7DA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE fiscal_invoice ADD CONSTRAINT FK_B35DFA7D412B6072 FOREIGN KEY (storno_of_id) REFERENCES fiscal_invoice (id)');
        $this->addSql('ALTER TABLE fiscal_invoice_line ADD CONSTRAINT FK_CF2456C8C1E11AFE FOREIGN KEY (fiscal_invoice_id) REFERENCES fiscal_invoice (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_invoice DROP FOREIGN KEY FK_B35DFA7D2989F1FD');
        $this->addSql('ALTER TABLE fiscal_invoice DROP FOREIGN KEY FK_B35DFA7DA76ED395');
        $this->addSql('ALTER TABLE fiscal_invoice DROP FOREIGN KEY FK_B35DFA7D412B6072');
        $this->addSql('ALTER TABLE fiscal_invoice_line DROP FOREIGN KEY FK_CF2456C8C1E11AFE');
        $this->addSql('DROP TABLE fiscal_invoice');
        $this->addSql('DROP TABLE fiscal_invoice_line');
        $this->addSql('DROP TABLE app_setting');
    }
}
