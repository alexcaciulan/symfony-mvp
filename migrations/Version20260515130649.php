<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pas 4.1 — Adăugare câmpuri pentru DeadlineService:
 * - LegalCase.rulingCommunicationDate (DATE_IMMUTABLE nullable) — data comunicării
 *   ordonanței către debitor, de la care curge termenul de 10 zile pentru cererea
 *   în anulare (CPC art. 1024 alin. 1).
 * - LegalDeadline.completedBy (ManyToOne User nullable, ON DELETE SET NULL) —
 *   userul care a marcat termenul ca încheiat, pentru audit trail vizibil în UI
 *   fără join la audit_log.
 */
final class Version20260515130649 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pas 4.1: LegalCase.rulingCommunicationDate + LegalDeadline.completedBy';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE legal_case ADD ruling_communication_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE legal_deadline ADD completed_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE legal_deadline ADD CONSTRAINT FK_1D8A033385ECDE76 FOREIGN KEY (completed_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1D8A033385ECDE76 ON legal_deadline (completed_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE legal_case DROP ruling_communication_date');
        $this->addSql('ALTER TABLE legal_deadline DROP FOREIGN KEY FK_1D8A033385ECDE76');
        $this->addSql('DROP INDEX IDX_1D8A033385ECDE76 ON legal_deadline');
        $this->addSql('ALTER TABLE legal_deadline DROP completed_by_id');
    }
}
