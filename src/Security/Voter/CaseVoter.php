<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Service\Filing\FilingLockQuery;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class CaseVoter extends Voter
{
    public const VIEW = 'CASE_VIEW';
    public const EDIT = 'CASE_EDIT';
    public const UPLOAD = 'CASE_UPLOAD';
    public const TRANSITION = 'CASE_TRANSITION';

    /**
     * Permite managementul termenelor procedurale (mark complete, add custom,
     * set rulingCommunicationDate) — ownership-only, FĂRĂ restricție de status.
     * Termenele pot exista pe dosare în orice stadiu (inclusiv ORDONANTA_EMISA,
     * DEFINITIVA, INCHIS_SUCCES) și avocatul trebuie să poată marca completarea
     * oricând. CASE_EDIT e prea restrictiv aici (limitat la AMIABIL/SOMATIE/CERERE).
     */
    public const DEADLINE_MANAGE = 'CASE_DEADLINE_MANAGE';

    /**
     * Permite gestionarea taxei de timbru (upload dovadă, amânare la regularizare,
     * data comunicării instanței): ownership-only, FĂRĂ restricție de status.
     * CASE_UPLOAD e prea restrictiv aici: când avocatul depune fără dovadă și
     * timbrează la regularizare, dosarul e deja DOSAR_INREGISTRAT în momentul în
     * care primește dovada de plată, iar el trebuie să o poată încărca.
     */
    public const STAMP_DUTY_MANAGE = 'CASE_STAMP_DUTY_MANAGE';

    /**
     * The files of a filing package, signed or not: uploading, confirming and
     * downloading them. Owner only, with no admin bypass, because their content is
     * covered by professional secrecy.
     */
    public const FILING_CONTENT = 'CASE_FILING_CONTENT';

    /** What would change the content of a filing the platform is transmitting. */
    private const LOCKED_BY_FILING = [self::EDIT, self::UPLOAD, self::STAMP_DUTY_MANAGE];

    private const EDITABLE_STATUSES = [
        CaseStatus::AMIABIL,
        CaseStatus::SOMATIE_TRIMISA,
        // The package exists but has not left yet, so an annex can still be added
        // and the package rebuilt before filing.
        CaseStatus::CERERE_GENERATA,
        CaseStatus::CERERE_DEPUSA,
    ];

    public function __construct(
        private readonly FilingLockQuery $filingLock,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::UPLOAD, self::TRANSITION, self::DEADLINE_MANAGE, self::STAMP_DUTY_MANAGE, self::FILING_CONTENT], true)
            && $subject instanceof LegalCase;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var LegalCase $legalCase */
        $legalCase = $subject;

        // Before the admin bypass: what was signed is on its way to the court, so
        // nobody changes its sources until the transmission ends.
        if (in_array($attribute, self::LOCKED_BY_FILING, true) && $this->filingLock->isLocked($legalCase)) {
            return false;
        }

        // Evaluated before the admin bypass on purpose: an admin sees the package
        // metadata on the case page, never the files themselves.
        if ($attribute === self::FILING_CONTENT) {
            return $this->canView($legalCase, $user);
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($legalCase, $user),
            self::EDIT => $this->canEdit($legalCase, $user),
            self::UPLOAD => $this->canUpload($legalCase, $user),
            self::TRANSITION => $this->canTransition($legalCase, $user),
            self::DEADLINE_MANAGE => $this->canManageDeadlines($legalCase, $user),
            self::STAMP_DUTY_MANAGE => $this->canManageStampDuty($legalCase, $user),
            default => false,
        };
    }

    private function canView(LegalCase $legalCase, User $user): bool
    {
        return $legalCase->getUser() === $user;
    }

    private function canEdit(LegalCase $legalCase, User $user): bool
    {
        return $legalCase->getUser() === $user
            && in_array($legalCase->getStatus(), self::EDITABLE_STATUSES, true);
    }

    private function canUpload(LegalCase $legalCase, User $user): bool
    {
        return $legalCase->getUser() === $user
            && in_array($legalCase->getStatus(), self::EDITABLE_STATUSES, true);
    }

    private function canTransition(LegalCase $legalCase, User $user): bool
    {
        return $legalCase->getUser() === $user;
    }

    /**
     * A case closed on full payment has nothing left to time: its deadlines were all
     * closed with it. Other terminal cases keep the right, because enforcement terms
     * still run after a closing without recovery.
     */
    private function canManageDeadlines(LegalCase $legalCase, User $user): bool
    {
        return $legalCase->getUser() === $user && !$this->isClosedOnFullPayment($legalCase);
    }

    private function isClosedOnFullPayment(LegalCase $legalCase): bool
    {
        return $legalCase->getStatus() === CaseStatus::INCHIS_SUCCES && $legalCase->getFullPaymentDate() !== null;
    }

    private function canManageStampDuty(LegalCase $legalCase, User $user): bool
    {
        return $legalCase->getUser() === $user;
    }
}
