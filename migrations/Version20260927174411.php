<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927174411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payment notice: contract object, penalty clause (article, text, accessory label) and notice number on legal_case';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD penalty_clause_article VARCHAR(100) DEFAULT NULL, ADD penalty_clause_text LONGTEXT DEFAULT NULL, ADD contractual_accessory_label VARCHAR(40) DEFAULT NULL, ADD contract_object VARCHAR(255) DEFAULT NULL, ADD payment_notice_number VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP penalty_clause_article, DROP penalty_clause_text, DROP contractual_accessory_label, DROP contract_object, DROP payment_notice_number');
    }
}
