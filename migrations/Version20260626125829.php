<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626125829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen court_portal_event.solutie_sumar and description to TEXT: real portal.just.ro ruling texts exceed the former 255/1000 char limits';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE court_portal_event CHANGE description description LONGTEXT NOT NULL, CHANGE solutie_sumar solutie_sumar LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE court_portal_event CHANGE description description VARCHAR(1000) NOT NULL, CHANGE solutie_sumar solutie_sumar VARCHAR(255) DEFAULT NULL');
    }
}
