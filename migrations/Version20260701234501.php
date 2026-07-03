<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701234501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Netopia recurring payments: add token/expiry/card-mask columns to subscription.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription ADD recurring_token VARCHAR(255) DEFAULT NULL, ADD recurring_token_expires_at DATE DEFAULT NULL, ADD card_mask VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP recurring_token, DROP recurring_token_expires_at, DROP card_mask');
    }
}
