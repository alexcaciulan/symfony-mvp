<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618164000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add summons accessory fields (penalty type, contractual rate, invoice/contract metadata, legal costs) and creditor bank name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor ADD bank_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE legal_case ADD penalty_type VARCHAR(30) DEFAULT NULL, ADD contractual_penalty_rate NUMERIC(5, 3) DEFAULT NULL, ADD contract_reference VARCHAR(100) DEFAULT NULL, ADD invoice_number VARCHAR(100) DEFAULT NULL, ADD invoice_date DATE DEFAULT NULL, ADD contract_number VARCHAR(100) DEFAULT NULL, ADD contract_date DATE DEFAULT NULL, ADD legal_costs_fixed NUMERIC(10, 2) DEFAULT NULL, ADD legal_costs_currency VARCHAR(3) DEFAULT NULL, ADD legal_costs_success_percent NUMERIC(5, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor DROP bank_name');
        $this->addSql('ALTER TABLE legal_case DROP penalty_type, DROP contractual_penalty_rate, DROP contract_reference, DROP invoice_number, DROP invoice_date, DROP contract_number, DROP contract_date, DROP legal_costs_fixed, DROP legal_costs_currency, DROP legal_costs_success_percent');
    }
}
