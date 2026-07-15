<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\LegalCase;
use App\Enum\DeadlineType;
use App\Enum\NotificationType;
use App\Event\DeadlineAlertEvent;
use App\Event\MissingCommunicationDateEvent;
use App\Event\PortalEventDetectedEvent;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps workflow transitions and monitoring events to user notifications, delegating
 * the email + in-app + Mercure fan-out to the dispatcher. Headless-safe (cron/worker).
 */
final class EmailNotificationSubscriber
{
    public function __construct(
        private readonly NotificationDispatcherInterface $dispatcher,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%app.base_url%')]
        private readonly string $baseUrl,
    ) {}

    #[AsEventListener(event: 'workflow.legal_case.entered.SOMATIE_TRIMISA')]
    public function onSomatieTrimisa(EnteredEvent $event): void
    {
        $this->notifyStatusChange($event, 'somatie_trimisa', 'info');
    }

    #[AsEventListener(event: 'workflow.legal_case.entered.CERERE_DEPUSA')]
    public function onCerereDepusa(EnteredEvent $event): void
    {
        $this->notifyStatusChange($event, 'cerere_depusa', 'info');
    }

    #[AsEventListener(event: 'workflow.legal_case.entered.ORDONANTA_EMISA')]
    public function onOrdonantaEmisa(EnteredEvent $event): void
    {
        $this->notifyStatusChange($event, 'ordonanta_emisa', 'success');
    }

    #[AsEventListener(event: 'workflow.legal_case.entered.IN_ANULARE')]
    public function onInAnulare(EnteredEvent $event): void
    {
        $this->notifyStatusChange($event, 'in_anulare', 'info');
    }

    #[AsEventListener(event: 'workflow.legal_case.entered.DEFINITIVA')]
    public function onDefinitiva(EnteredEvent $event): void
    {
        // A rejected annulment request makes the order final (CPC art. 1024 alin. 8);
        // the plain marcheaza_definitiva path is the ordinary "became final" case.
        if ($event->getTransition()?->getName() === 'respinge_cerere_anulare') {
            $this->notifyStatusChange($event, 'anulare_respinsa', 'success');

            return;
        }

        $this->notifyStatusChange($event, 'definitiva', 'success');
    }

    #[AsEventListener(event: 'workflow.legal_case.entered.RESPINSA')]
    public function onRespinsa(EnteredEvent $event): void
    {
        // An admitted annulment request annuls the payment order (CPC art. 1024);
        // otherwise RESPINSA is the rejected OP request.
        if ($event->getTransition()?->getName() === 'admite_cerere_anulare') {
            $this->notifyStatusChange($event, 'anulare_admisa', 'warning');

            return;
        }

        $this->notifyStatusChange($event, 'respinsa', 'warning');
    }

    #[AsEventListener(event: DeadlineAlertEvent::class)]
    public function onDeadlineAlert(DeadlineAlertEvent $event): void
    {
        $deadline = $event->deadline;
        $case = $deadline->getLegalCase();

        // PRESCRIPTIE is a material-law term (non-prorogable, NCC art. 2517);
        // procedural terms like CERERE_IN_ANULARE run from service (CPC art. 1024).
        $group = $deadline->getType() === DeadlineType::PRESCRIPTIE ? 'prescriptie' : 'procedural';
        $state = $event->isExpired() ? 'expired' : 'upcoming';
        $variant = ($event->isExpired() || $event->daysRemaining <= 1) ? 'error' : 'warning';

        $params = [
            '%case%' => $this->caseLabel($case),
            '%type%' => $this->translator->trans($deadline->getType()->label()),
            '%date%' => $deadline->getDeadlineDate()->format('d.m.Y'),
            '%days%' => abs($event->daysRemaining),
        ];

        $title = $this->translator->trans("notification.deadline_alert.$group.title", $params);
        $message = $this->translator->trans("notification.deadline_alert.$group.$state", $params);

        $this->dispatcher->dispatch(new NotificationDispatch(
            user: $case->getUser(),
            legalCase: $case,
            type: NotificationType::DEADLINE_ALERT,
            title: $title,
            message: $message,
            resourceLink: $this->caseLink($case),
            variant: $variant,
            emailSubject: $this->translator->trans("email.deadline_alert.$group.subject", $params),
            emailTemplate: 'emails/deadline_alert.html.twig',
            emailContext: [
                'case' => $case,
                'caseLabel' => $this->caseLabel($case),
                'caseUrl' => $this->caseUrl($case),
                'deadlineType' => $params['%type%'],
                'deadlineDate' => $deadline->getDeadlineDate(),
                'isMaterial' => $group === 'prescriptie',
                'isExpired' => $event->isExpired(),
                'heading' => $title,
                'message' => $message,
                'disclaimer' => $this->translator->trans("email.deadline_alert.$group.disclaimer"),
            ],
        ));
    }

    #[AsEventListener(event: MissingCommunicationDateEvent::class)]
    public function onMissingCommunicationDate(MissingCommunicationDateEvent $event): void
    {
        $case = $event->case;
        $params = ['%case%' => $this->caseLabel($case)];

        $title = $this->translator->trans('notification.missing_communication_date.title', $params);
        $message = $this->translator->trans('notification.missing_communication_date.message', $params);

        $this->dispatcher->dispatch(new NotificationDispatch(
            user: $case->getUser(),
            legalCase: $case,
            type: NotificationType::MISSING_COMMUNICATION_DATE,
            title: $title,
            message: $message,
            resourceLink: $this->caseLink($case),
            variant: 'warning',
            emailSubject: $this->translator->trans('email.missing_communication_date.subject', $params),
            emailTemplate: 'emails/missing_communication_date.html.twig',
            emailContext: [
                'case' => $case,
                'caseLabel' => $this->caseLabel($case),
                'caseUrl' => $this->caseUrl($case),
                'heading' => $this->translator->trans('email.missing_communication_date.heading', $params),
                'body' => $this->translator->trans('email.missing_communication_date.body', $params),
            ],
        ));
    }

    #[AsEventListener(event: PortalEventDetectedEvent::class)]
    public function onPortalEventDetected(PortalEventDetectedEvent $event): void
    {
        $case = $event->case;
        $portalEvent = $event->event;
        $params = [
            '%case%' => $this->caseLabel($case),
            '%event%' => $this->translator->trans($portalEvent->getEventType()->label()),
        ];

        $title = $this->translator->trans('notification.portal_event.title', $params);
        $message = $this->translator->trans('notification.portal_event.message', $params);

        $this->dispatcher->dispatch(new NotificationDispatch(
            user: $case->getUser(),
            legalCase: $case,
            type: NotificationType::PORTAL_EVENT,
            title: $title,
            message: $message,
            resourceLink: $this->caseLink($case),
            variant: 'info',
            emailSubject: $this->translator->trans('email.portal_event.subject', $params),
            emailTemplate: 'emails/portal_event.html.twig',
            emailContext: [
                'case' => $case,
                'caseLabel' => $this->caseLabel($case),
                'caseUrl' => $this->caseUrl($case),
                'eventType' => $params['%event%'],
                'eventDate' => $portalEvent->getEventDate(),
                'description' => $portalEvent->getDescription(),
                'solutie' => $portalEvent->getSolutie(),
                'solutieSumar' => $portalEvent->getSolutieSumar(),
                'heading' => $this->translator->trans('email.portal_event.heading', $params),
                'body' => $this->translator->trans('email.portal_event.body', $params),
            ],
            // CaseMonitoringService already persists the in-app portal_event row.
            persistInApp: false,
        ));
    }

    private function notifyStatusChange(EnteredEvent $event, string $place, string $variant): void
    {
        $case = $event->getSubject();
        if (!$case instanceof LegalCase || $case->getId() === null) {
            return;
        }

        $params = ['%case%' => $this->caseLabel($case)];
        $title = $this->translator->trans("notification.case_status.$place.title", $params);
        $message = $this->translator->trans("notification.case_status.$place.message", $params);

        $this->dispatcher->dispatch(new NotificationDispatch(
            user: $case->getUser(),
            legalCase: $case,
            type: NotificationType::CASE_STATUS,
            title: $title,
            message: $message,
            resourceLink: $this->caseLink($case),
            variant: $variant,
            emailSubject: $this->translator->trans("email.case_status.$place.subject", $params),
            emailTemplate: 'emails/case_status.html.twig',
            emailContext: [
                'case' => $case,
                'caseLabel' => $this->caseLabel($case),
                'caseUrl' => $this->caseUrl($case),
                'heading' => $this->translator->trans("email.case_status.$place.heading", $params),
                'body' => $this->translator->trans("email.case_status.$place.body", $params),
            ],
        ));
    }

    private function caseLabel(LegalCase $case): string
    {
        return $case->getCourtCaseNumber() ?? $case->getCaseNumber();
    }

    private function caseLink(LegalCase $case): string
    {
        return $this->urlGenerator->generate('case_overview', ['id' => $case->getId()]);
    }

    private function caseUrl(LegalCase $case): string
    {
        return rtrim($this->baseUrl, '/') . $this->caseLink($case);
    }
}
