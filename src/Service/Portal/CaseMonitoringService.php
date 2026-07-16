<?php

declare(strict_types=1);

namespace App\Service\Portal;

use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Event\PortalEventDetectedEvent;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

class CaseMonitoringService
{
    public function __construct(
        private PortalJustClient $portalClient,
        private PortalEventDetector $eventDetector,
        private MonitoringEventApplier $eventApplier,
        private EntityManagerInterface $em,
        private AuditLogService $auditLogService,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * Monitor a single case: query portal, detect events, persist, notify.
     *
     * @return int Number of new events detected
     */
    public function monitorCase(LegalCase $case): int
    {
        $court = $case->getCourt();
        if ($court === null || $court->getPortalCode() === null) {
            $this->logger->warning('Case #{id} has no court or no portal code, skipping', [
                'id' => $case->getId(),
            ]);

            return 0;
        }

        if ($case->getCourtCaseNumber() === null) {
            $this->logger->warning('Case #{id} has no court case number, skipping', [
                'id' => $case->getId(),
            ]);

            return 0;
        }

        try {
            $dosarList = $this->portalClient->searchByCaseNumber(
                $case->getCourtCaseNumber(),
                $court->getPortalCode(),
            );
        } catch (PortalJustException $e) {
            $this->logger->error('Portal query failed for case #{id}: {error}', [
                'id' => $case->getId(),
                'error' => $e->getMessage(),
            ]);

            $this->auditLogService->log(
                'portal_query_failed',
                'LegalCase',
                (string) $case->getId(),
                null,
                ['error' => $e->getMessage()],
            );
            $this->em->flush();

            // Propagate so the async handler can retry. Synchronous callers
            // (CasePortalController activate/check-now) already catch \Throwable,
            // so there is no regression.
            throw $e;
        }

        if (empty($dosarList)) {
            $case->setLastPortalCheckAt(new \DateTimeImmutable());
            $this->em->flush();

            return 0;
        }

        // Use the first matching dosar
        $dosarData = $dosarList[0];
        $newEvents = $this->eventDetector->detectNewEvents($case, $dosarData);

        /** @var CourtPortalEvent[] $persistedEvents */
        $persistedEvents = [];
        foreach ($newEvents as $eventData) {
            $event = new CourtPortalEvent();
            $event->setLegalCase($case);
            $event->setEventType($eventData['type']);
            $event->setEventDate($eventData['eventDate']);
            $event->setDescription($eventData['description']);
            $event->setSolutie($eventData['solutie']);
            $event->setSolutieSumar($eventData['solutieSumar']);
            $event->setRawData($eventData['rawData']);
            $event->setNotified(true);
            $this->em->persist($event);
            $persistedEvents[] = $event;

            $this->auditLogService->log(
                'portal_event_detected',
                'CourtPortalEvent',
                (string) $case->getId(),
                null,
                [
                    'eventType' => $eventData['type']->value,
                    'eventDate' => $eventData['eventDate']?->format('Y-m-d'),
                    'description' => $eventData['description'],
                ],
            );
        }

        $case->setLastPortalCheckAt(new \DateTimeImmutable());
        $this->em->flush();

        // Notify the lawyer per persisted event. Dispatched after flush so each
        // CourtPortalEvent has an id; the subscriber fans out email + toast and
        // persists the single in-app portal_event row via the dispatcher.
        foreach ($persistedEvents as $portalEvent) {
            $this->eventDispatcher->dispatch(new PortalEventDetectedEvent($case, $portalEvent));
        }

        // Propagate events into the workflow: safe AUTO transitions + proposals
        // for sensitive ones. Runs after flush (the applier needs persisted
        // events) and flushes itself per transition/deadline.
        $this->eventApplier->applyEvents($case, $persistedEvents);

        return count($newEvents);
    }
}
