<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710110919 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BNR exchange rate series + FX conversion audit columns on legal_case';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bnr_exchange_rate (id INT AUTO_INCREMENT NOT NULL, currency VARCHAR(3) NOT NULL, rate_date DATE NOT NULL, rate NUMERIC(10, 4) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_currency_rate_date (currency, rate_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE legal_case ADD original_amount NUMERIC(12, 2) DEFAULT NULL, ADD original_currency VARCHAR(3) DEFAULT NULL, ADD exchange_rate NUMERIC(10, 4) DEFAULT NULL, ADD exchange_rate_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE bnr_exchange_rate');
        $this->addSql('ALTER TABLE legal_case DROP original_amount, DROP original_currency, DROP exchange_rate, DROP exchange_rate_date');
    }
}
