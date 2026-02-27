<?php

namespace App\Service\Portal;

use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Entity\Notification;
use App\Enum\NotificationChannel;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class CaseMonitoringService
{
    public function __construct(
        private PortalJustClient $portalClient,
        private PortalEventDetector $eventDetector,
        private EntityManagerInterface $em,
        private AuditLogService $auditLogService,
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

        if ($case->getCaseNumber() === null) {
            $this->logger->warning('Case #{id} has no case number, skipping', [
                'id' => $case->getId(),
            ]);

            return 0;
        }

        try {
            $dosarList = $this->portalClient->searchByCaseNumber(
                $case->getCaseNumber(),
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

            return 0;
        }

        if (empty($dosarList)) {
            $case->setLastPortalCheckAt(new \DateTimeImmutable());
            $this->em->flush();

            return 0;
        }

        // Use the first matching dosar
        $dosarData = $dosarList[0];
        $newEvents = $this->eventDetector->detectNewEvents($case, $dosarData);

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

            $this->createNotification($case, $event);

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

        return count($newEvents);
    }

    private function createNotification(LegalCase $case, CourtPortalEvent $event): void
    {
        $notification = new Notification();
        $notification->setUser($case->getUser());
        $notification->setLegalCase($case);
        $notification->setType('portal_update');
        $notification->setChannel(NotificationChannel::IN_APP);
        $notification->setTitle(sprintf(
            'Actualizare dosar %s: %s',
            $case->getCaseNumber(),
            $event->getEventType()->label(),
        ));
        $notification->setMessage($event->getDescription());
        $notification->setResourceLink('/case/' . $case->getId());
        $this->em->persist($notification);
    }
}
