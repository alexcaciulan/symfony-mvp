<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260629201401 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Additive pre-conditions for workflow revision: summons communication date/method + OP-generation consent + acknowledged debit status on legal_case';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case ADD payment_notice_communication_date DATE DEFAULT NULL, ADD payment_notice_communication_method VARCHAR(20) DEFAULT NULL, ADD op_generation_consent TINYINT DEFAULT NULL, ADD debit_acknowledged_status VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legal_case DROP payment_notice_communication_date, DROP payment_notice_communication_method, DROP op_generation_consent, DROP debit_acknowledged_status');
    }
}
