<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditLogService
{
    /** AI-extraction calls (OCR + AI cascade). Indexed for GDPR audit queries. */
    public const CATEGORY_AI_EXTRACTION = 'AI_EXTRACTION';

    /** Final wizard submit. `newData` records the per-field auto-vs-manual split. */
    public const CATEGORY_WIZARD_SUBMIT = 'WIZARD_SUBMIT';

    /** Somația de plată generation (AMIABIL → SOMATIE_TRIMISA). */
    public const CATEGORY_SUMMONS_GENERATED = 'SUMMONS_GENERATED';

    /** Procedural deadline creation. `newData` records the prorogation per CPC art. 181 alin. 2. */
    public const CATEGORY_DEADLINE_CREATED = 'DEADLINE_CREATED';

    /** Manual completion of a procedural deadline. */
    public const CATEGORY_DEADLINE_COMPLETED = 'DEADLINE_COMPLETED';

    /** Manual edit of a deadline (date/description) by the lawyer. */
    public const CATEGORY_DEADLINE_EDITED = 'DEADLINE_EDITED';

    /** Manual deletion of a deadline by the lawyer. */
    public const CATEGORY_DEADLINE_DELETED = 'DEADLINE_DELETED';

    /** Payment-order petition + opis generation (SOMATIE_TRIMISA → CERERE_DEPUSA). */
    public const CATEGORY_PAYMENT_ORDER_GENERATED = 'PAYMENT_ORDER_GENERATED';

    /**
     * Judicial stamp duty: proof uploaded, payment deferred to the court's
     * regularization procedure, or the court's notice date recorded. The platform
     * never handles the money, so this trail is the only record of what the lawyer
     * was told to pay and what they chose to do.
     */
    public const CATEGORY_STAMP_DUTY = 'STAMP_DUTY';

    /** portal.just.ro monitoring: activation, auto-applied transitions and proposed ones. */
    public const CATEGORY_PORTAL_MONITORING = 'PORTAL_MONITORING';

    /** Automatic transition to DEFINITIVA after the annulment window lapses (CPC art. 1024). */
    public const CATEGORY_AUTO_FINALIZED = 'AUTO_FINALIZED';

    /** Manual `inregistreaza_dosar`: CERERE_DEPUSA → DOSAR_INREGISTRAT with ECRIS courtCaseNumber. */
    public const CATEGORY_CASE_REGISTERED = 'CASE_REGISTERED';

    /** Manual `emite_ordonanta`: TERMEN_FIXAT → ORDONANTA_EMISA with rulingDate. */
    public const CATEGORY_RULING_ISSUED = 'RULING_ISSUED';

    /** Manual `respinge` / `admite_cerere_anulare`: both transitions land in RESPINSA. */
    public const CATEGORY_CASE_REJECTED = 'CASE_REJECTED';

    /**
     * Manual `respinge_cerere_anulare` / `respinge_cerere_anulare_executare`: the
     * annulment request was dismissed, so the order stands (lands in DEFINITIVA
     * from IN_ANULARE, or stays in EXECUTARE). Opposite outcome to CASE_REJECTED.
     */
    public const CATEGORY_ANNULMENT_REJECTED = 'ANNULMENT_REJECTED';

    /** Manual `inchide_succes` / `inchide_fara_recuperare` from DEFINITIVA or EXECUTARE. */
    public const CATEGORY_CASE_CLOSED = 'CASE_CLOSED';

    /** Manual `trece_la_executare` from ORDONANTA_EMISA / IN_ANULARE / DEFINITIVA: enforcement phase started. */
    public const CATEGORY_EXECUTION_STARTED = 'EXECUTION_STARTED';

    /** Billing events: slot consumption, invoice creation, payment, trial, renew/cancel. */
    public const CATEGORY_BILLING = 'BILLING';

    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack,
    ) {}

    /**
     * Persists an audit-log entry. `$oldData` and `$newData` are stored verbatim
     * in the JSON column. Callers are responsible for masking Romanian PII (CNP
     * especially) with {@see \App\Util\PiiMasker::maskCnpInArray()} before
     * invoking this method.
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
