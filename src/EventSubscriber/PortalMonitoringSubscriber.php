<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\LegalCase;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Pas 6.1 — observă activarea monitorizării portal pe `LegalCase` (Doctrine
 * `postUpdate`) și o loghează. Detectează tranziția `portalMonitoringActive`
 * false→true sau `courtCaseNumber` null→non-null pe baza changeset-ului
 * UnitOfWork.
 *
 * **Pur observațional (logger only), fără persist în lifecycle** — un `flush()`
 * în interiorul unui `postUpdate` ar re-declanșa UnitOfWork. Auditul durabil al
 * activării (AuditLog) se face explicit în
 * {@see \App\Controller\Case\CasePortalController::activate()} după flush.
 */
#[AsDoctrineListener(event: Events::postUpdate)]
final class PortalMonitoringSubscriber
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof LegalCase) {
            return;
        }

        $changeSet = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity);

        $monitoringActivated = isset($changeSet['portalMonitoringActive'])
            && $changeSet['portalMonitoringActive'][0] === false
            && $changeSet['portalMonitoringActive'][1] === true;

        $courtNumberSet = isset($changeSet['courtCaseNumber'])
            && ($changeSet['courtCaseNumber'][0] === null)
            && ($changeSet['courtCaseNumber'][1] !== null);

        if (!$monitoringActivated && !$courtNumberSet) {
            return;
        }

        $this->logger->info('Portal monitoring activated for case', [
            'caseId' => $entity->getId(),
            'caseNumber' => $entity->getCaseNumber(),
            'courtCaseNumber' => $entity->getCourtCaseNumber(),
        ]);
    }
}
