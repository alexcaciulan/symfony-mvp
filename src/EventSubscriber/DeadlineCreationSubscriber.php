<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\LegalCase;
use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use App\Service\Deadline\DeadlineService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\EnteredEvent;

/**
 * Pas 4.2 — auto-creează termene procedurale via DeadlineService în reacție la
 * workflow events + Doctrine `postPersist` LegalCase. Idempotency prin
 * `findOneByCaseAndType()`. Excepțiile DeadlineService sunt log-uite, NU
 * propagate (NU rupe flush/apply).
 */
#[AsDoctrineListener(event: Events::postPersist)]
final class DeadlineCreationSubscriber
{
    /**
     * Avertizare juridică obligatorie pe `LegalDeadline.description` pentru
     * RASPUNS_SOMATIE: termenul calculat e estimat (de la data generării PDF),
     * NU de la data primirii efective de către debitor — CPC art. 1015 alin. 1
     * spune că cele 15 zile curg de la PRIMIRE. Avocatul trebuie să ajusteze
     * manual data când are dovada comunicării.
     */
    private const PAYMENT_NOTICE_DEADLINE_DISCLAIMER = 'Termen estimativ. Calculat de la data generării somației. Actualizați după confirmarea primirii de către debitor (CPC art. 1015 alin. 1 — termenul curge de la primire).';

    public function __construct(
        private readonly DeadlineService $deadlineService,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    #[AsEventListener(event: 'workflow.legal_case.entered.SOMATIE_TRIMISA')]
    public function onSomatieTrimisa(EnteredEvent $event): void
    {
        $case = $event->getSubject();
        if (!$case instanceof LegalCase || $case->getId() === null) {
            return;
        }

        if ($this->hasDeadline($case, DeadlineType::RASPUNS_SOMATIE)) {
            return;
        }

        $paymentNoticeDate = $case->getPaymentNoticeDate();
        if ($paymentNoticeDate === null) {
            // Eroare de ordonare: CaseSummonsController setează paymentNoticeDate
            // ÎNAINTE de workflow apply. Dacă e null aici → bug în caller.
            $this->logger->error('Skipping RASPUNS_SOMATIE deadline: paymentNoticeDate not set on workflow entered', [
                'caseNumber' => $case->getCaseNumber(),
            ]);

            return;
        }

        try {
            $deadline = $this->deadlineService->createPaymentNoticeDeadline(
                $case,
                \DateTimeImmutable::createFromInterface($paymentNoticeDate),
            );
            $deadline->setDescription(self::PAYMENT_NOTICE_DEADLINE_DISCLAIMER);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create RASPUNS_SOMATIE deadline', [
                'caseNumber' => $case->getCaseNumber(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    #[AsEventListener(event: 'workflow.legal_case.entered.ORDONANTA_EMISA')]
    public function onOrdonantaEmisa(EnteredEvent $event): void
    {
        $case = $event->getSubject();
        if (!$case instanceof LegalCase || $case->getId() === null) {
            return;
        }

        if ($this->hasDeadline($case, DeadlineType::CERERE_IN_ANULARE)) {
            return;
        }

        $communicationDate = $case->getRulingCommunicationDate();
        if ($communicationDate === null) {
            // Comportament normal: la tranziție ORDONANTA_EMISA, avocatul încă
            // NU are data comunicării (ordonanța se comunică ulterior).
            $this->logger->info('Skipping CERERE_IN_ANULARE deadline: rulingCommunicationDate not set yet', [
                'caseNumber' => $case->getCaseNumber(),
            ]);

            return;
        }

        try {
            $this->deadlineService->createAppealDeadline($case, $communicationDate);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create CERERE_IN_ANULARE deadline', [
                'caseNumber' => $case->getCaseNumber(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof LegalCase) {
            return;
        }

        if ($entity->getDueDate() === null) {
            $this->logger->info('Skipping PRESCRIPTIE deadline: dueDate not set on case', [
                'caseNumber' => $entity->getCaseNumber(),
            ]);

            return;
        }

        if ($this->hasDeadline($entity, DeadlineType::PRESCRIPTIE)) {
            return;
        }

        try {
            $this->deadlineService->createPrescriptionDeadline($entity);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create PRESCRIPTIE deadline', [
                'caseNumber' => $entity->getCaseNumber(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function hasDeadline(LegalCase $case, DeadlineType $type): bool
    {
        return $this->deadlineRepository->findOneByCaseAndType($case, $type) !== null;
    }
}
