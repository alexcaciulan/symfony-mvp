<?php

declare(strict_types=1);

namespace App\Service\Portal;

use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Enum\NotificationType;
use App\Event\PortalEventDetectedEvent;
use App\Service\AuditLogService;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CaseMonitoringService
{
    /** Consecutive portal failures after which monitoring is stopped. */
    private const MAX_PORTAL_FAILURES = 5;

    public function __construct(
        private PortalJustClient $portalClient,
        private PortalEventDetector $eventDetector,
        private MonitoringEventApplier $eventApplier,
        private EntityManagerInterface $em,
        private AuditLogService $auditLogService,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private NotificationDispatcherInterface $notificationDispatcher,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
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

            $case->incrementPortalConsecutiveFailures();

            $this->auditLogService->log(
                'portal_query_failed',
                'LegalCase',
                (string) $case->getId(),
                null,
                ['error' => $e->getMessage()],
            );

            if ($case->getPortalConsecutiveFailures() >= self::MAX_PORTAL_FAILURES) {
                // Threshold reached: stop monitoring, notify the lawyer, and end
                // the retry chain by returning instead of re-throwing.
                $case->setPortalMonitoringActive(false);
                $this->em->flush();

                $this->notifyMonitoringStopped($case);

                return 0;
            }

            $this->em->flush();

            // Propagate so the async handler can retry. Synchronous callers
            // (CasePortalController activate/check-now) already catch \Throwable,
            // so there is no regression.
            throw $e;
        }

        // A successful query clears the failure streak so a recovered case
        // starts clean; persisted with the flush(es) below.
        $case->resetPortalConsecutiveFailures();

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

    /**
     * Portal monitoring was automatically stopped after too many consecutive
     * failed queries. Notify the lawyer so they can fix the case number or
     * re-activate monitoring. In-app only, no email template. Dispatched after
     * flush. Fires exactly once per deactivation (the cron only dispatches for
     * cases with monitoring active, which is now off), so no dedupKey is needed.
     */
    private function notifyMonitoringStopped(LegalCase $case): void
    {
        $params = ['%case%' => $case->getCourtCaseNumber() ?? $case->getCaseNumber()];

        $this->notificationDispatcher->dispatch(new NotificationDispatch(
            user: $case->getUser(),
            legalCase: $case,
            type: NotificationType::PORTAL_QUERY_FAILED,
            title: $this->translator->trans('notification.portal_query_failed.title', $params),
            message: $this->translator->trans('notification.portal_query_failed.message', $params),
            resourceLink: $this->urlGenerator->generate('case_overview', ['id' => $case->getId()]),
            variant: 'error',
            emailSubject: null,
            emailTemplate: null,
        ));
    }
}
