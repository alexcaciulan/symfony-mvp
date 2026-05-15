<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditLogService
{
    /**
     * Audit category for AI-extraction calls (Pas 2.5.7+ OcrTextExtractionStrategy
     * + Pas 2.5.8 AiVisionExtractionStrategy). Persisted on `AuditLog.category`
     * so that GDPR-driven queries ("which documents had data sent to which AI
     * provider?") can filter via a single indexed lookup.
     */
    public const CATEGORY_AI_EXTRACTION = 'AI_EXTRACTION';

    /**
     * Audit category for the case wizard final submit (Pas 3.2
     * CaseWizardController). `newData` records the per-field source split
     * (`fields_auto` from extraction vs `fields_manual` entered by the user)
     * plus the extracted document IDs and any non-blocking admissibility
     * warnings the user acknowledged before submit. Lets us answer "which
     * fields on this case came from AI extraction?" without re-reading
     * historical extraction payloads.
     */
    public const CATEGORY_WIZARD_SUBMIT = 'WIZARD_SUBMIT';

    /**
     * Audit category for somația de plată generation (Pas 5.1
     * CaseSummonsController). `newData` records the generated `documentId`,
     * the `caseNumber`, and the `paymentNoticeDate` set at the moment of
     * transition AMIABIL → SOMATIE_TRIMISA. Lets us reconstruct the legal
     * timeline for each case (when CPC art. 1015 alin. 1 was triggered).
     */
    public const CATEGORY_SUMMONS_GENERATED = 'SUMMONS_GENERATED';

    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack,
    ) {}

    /**
     * Persists an audit-log entry. Caller-controlled fields (`$oldData`, `$newData`)
     * are stored verbatim in the JSON column — this is intentional, callers are
     * responsible for masking PII before invoking the service.
     *
     * For payloads that may contain Romanian PII (CNP especially), apply
     * {@see \App\Util\PiiMasker::maskCnpInArray()} before passing the array
     * to this method:
     *
     *   $oldData = PiiMasker::maskCnpInArray($oldData);
     *   $auditLogService->log(..., $oldData, ..., AuditLogService::CATEGORY_AI_EXTRACTION);
     *
     * This applies in particular to AI-extraction callers (Pas 2.5.7
     * OcrTextExtractionStrategy + 2.5.8 AiVisionExtractionStrategy) where
     * the JSON payload reflects content sent to / received from external AI.
     */
    public function log(
        string $action,
        string $entityType,
        string $entityId,
        ?array $oldData = null,
        ?array $newData = null,
        ?string $category = null,
    ): AuditLog {
        $auditLog = new AuditLog();
        $auditLog->setUser($this->security->getUser());
        $auditLog->setAction($action);
        $auditLog->setEntityType($entityType);
        $auditLog->setEntityId($entityId);
        $auditLog->setOldData($oldData);
        $auditLog->setNewData($newData);
        $auditLog->setCategory($category);

        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $auditLog->setIpAddress($request->getClientIp());
        }

        $this->em->persist($auditLog);

        return $auditLog;
    }
}
