<?php

namespace App\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\CaseStatusHistory;
use App\Entity\LegalCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Workflow\Event\CompletedEvent;

class CaseWorkflowSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.legal_case.completed' => 'onCompleted',
        ];
    }

    public function onCompleted(CompletedEvent $event): void
    {
        $subject = $event->getSubject();

        if (!$subject instanceof LegalCase) {
            return;
        }

        $transition = $event->getTransition();
        $oldStatus = $transition->getFroms()[0];
        $newStatus = $transition->getTos()[0];
        $user = $this->security->getUser();

        // Create CaseStatusHistory entry
        $history = new CaseStatusHistory();
        $history->setLegalCase($subject);
        $history->setOldStatus($oldStatus);
        $history->setNewStatus($newStatus);
        $history->setCreatedBy($user);
        $this->em->persist($history);

        // Create AuditLog entry
        $auditLog = new AuditLog();
        $auditLog->setUser($user);
        $auditLog->setAction('case_status_change');
        $auditLog->setEntityType('LegalCase');
        $auditLog->setEntityId((string) $subject->getId());
        $auditLog->setOldData(['status' => $oldStatus]);
        $auditLog->setNewData(['status' => $newStatus]);

        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $auditLog->setIpAddress($request->getClientIp());
        }

        $this->em->persist($auditLog);
    }
}
