<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\CaseStatusHistory;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineBlockageReason;
use App\Enum\DeadlineType;
use App\Enum\DocumentType;
use App\Enum\PersonType;
use App\Enum\StampDutyStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The account behind the deadlines page, for the WebTestCase classes that read it.
 * Expects `$this->client`, `$this->em` and `$this->prefix` on the test.
 */
trait AgendaPageFixtureTrait
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $prefix;
    private int $caseSequence = 0;

    private function makeUser(string $suffix = ''): User
    {
        $user = new User();
        $user->setEmail($this->prefix . $suffix . '@test.com');
        $user->setPassword('not-used');
        $user->setIsVerified(true);
        $this->em->persist($user);

        return $user;
    }

    private function makeCase(User $user, CaseStatus $status = CaseStatus::AMIABIL): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus($status);
        $case->setAmount('1000.00');
        // The generated number is random within a second, which a page of cases can hit.
        $case->setCaseNumber($this->prefix . '-' . ++$this->caseSequence);
        $this->em->persist($case);

        return $case;
    }

    private function makeDeadline(LegalCase $case, string $date, DeadlineType $type, bool $completed = false): LegalDeadline
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType($type);
        $deadline->setDeadlineDate(new \DateTimeImmutable($date));
        $deadline->setPriority($type->defaultPriority());
        $deadline->setCompleted($completed);
        $this->em->persist($deadline);

        return $deadline;
    }

    private function closedDeadline(LegalCase $case, User $by, string $date = '-5 days'): LegalDeadline
    {
        $deadline = $this->makeDeadline($case, $date, DeadlineType::OTHER);
        $deadline->markCompleted($by);

        return $deadline;
    }

    private function attachDebtor(LegalCase $case, string $name): void
    {
        $debtor = new Debtor();
        $debtor->setUser($case->getUser());
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName($name);
        $debtor->setAddress('str. Testului nr. 1');
        $this->em->persist($debtor);

        $link = new LegalCaseDebtor($debtor);
        $case->addDebtor($link);
        $this->em->persist($link);
    }

    private function attachProof(LegalCase $case): void
    {
        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType(DocumentType::DOVADA_COMUNICARE);
        $document->setOriginalFilename('dovada.pdf');
        $document->setStoredFilename($this->prefix . '/' . uniqid() . '.pdf');
        $document->setFileSize(1024);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($case->getUser());
        $this->em->persist($document);
    }

    /** A case stuck on exactly the given reason. */
    private function blockedCase(User $user, DeadlineBlockageReason $reason): LegalCase
    {
        $case = match ($reason) {
            DeadlineBlockageReason::SUMMONS_COMMUNICATION_MISSING,
            DeadlineBlockageReason::SUMMONS_PROOF_MISSING => $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA),
            DeadlineBlockageReason::RULING_COMMUNICATION_MISSING => $this->makeCase($user, CaseStatus::ORDONANTA_EMISA),
            DeadlineBlockageReason::STAMP_DUTY_NOTICE_MISSING => $this->makeCase($user, CaseStatus::CERERE_DEPUSA),
            DeadlineBlockageReason::EXECUTION_ANCHOR_MISSING,
            DeadlineBlockageReason::ANNULMENT_RULING_COMMUNICATION_MISSING => $this->makeCase($user, CaseStatus::DEFINITIVA),
            DeadlineBlockageReason::ENFORCEMENT_REGISTRATION_NUMBER_MISSING => $this->makeCase($user, CaseStatus::EXECUTARE),
        };

        match ($reason) {
            DeadlineBlockageReason::SUMMONS_COMMUNICATION_MISSING => $case->setPaymentNoticeDate(new \DateTime('-6 days')),
            DeadlineBlockageReason::SUMMONS_PROOF_MISSING => $case
                ->setPaymentNoticeDate(new \DateTime('-6 days'))
                ->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-3 days')),
            DeadlineBlockageReason::STAMP_DUTY_NOTICE_MISSING => $case->setStampDutyStatus(StampDutyStatus::NEACHITATA),
            DeadlineBlockageReason::ANNULMENT_RULING_COMMUNICATION_MISSING => $this->markThroughAnnulment($case),
            DeadlineBlockageReason::ENFORCEMENT_REGISTRATION_NUMBER_MISSING => $case->setEnforcementRequestDate(new \DateTimeImmutable('-3 days')),
            default => null,
        };

        return $case;
    }

    /** Writes the history entry that proves the case entered IN_ANULARE at some point. */
    private function markThroughAnnulment(LegalCase $case): void
    {
        $case->setRulingCommunicationDate(new \DateTimeImmutable('-90 days'));

        $entry = new CaseStatusHistory();
        $entry->setLegalCase($case);
        $entry->setOldStatus(CaseStatus::ORDONANTA_EMISA->value);
        $entry->setNewStatus(CaseStatus::IN_ANULARE->value);
        $this->em->persist($entry);
    }

    private function removeAgendaFixtures(): void
    {
        $like = [$this->prefix . '%'];
        $conn = $this->em->getConnection();

        foreach ([
            'DELETE a FROM audit_log a JOIN `user` u ON a.user_id = u.id WHERE u.email LIKE ?',
            'DELETE h FROM case_status_history h JOIN legal_case lc ON h.legal_case_id = lc.id JOIN `user` u ON lc.user_id = u.id WHERE u.email LIKE ?',
            'DELETE doc FROM document doc JOIN legal_case lc ON doc.legal_case_id = lc.id JOIN `user` u ON lc.user_id = u.id WHERE u.email LIKE ?',
            'DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id JOIN `user` u ON lc.user_id = u.id WHERE u.email LIKE ?',
            'DELETE l FROM legal_case_debtor l JOIN legal_case lc ON l.legal_case_id = lc.id JOIN `user` u ON lc.user_id = u.id WHERE u.email LIKE ?',
            'DELETE lc FROM legal_case lc JOIN `user` u ON lc.user_id = u.id WHERE u.email LIKE ?',
            'DELETE d FROM debtor d JOIN `user` u ON d.user_id = u.id WHERE u.email LIKE ?',
            'DELETE FROM `user` WHERE email LIKE ?',
        ] as $statement) {
            $conn->executeStatement($statement, $like);
        }
    }

    /** @return list<int> ids of the rows the page rendered, in page order */
    private function rowIdsIn(Crawler $crawler): array
    {
        return $crawler->filter('article[id^="deadline-row-"]')->each(
            static fn (Crawler $node): int => (int) str_replace('deadline-row-', '', (string) $node->attr('id')),
        );
    }

    private function counterIn(Crawler $crawler, string $counter): string
    {
        return trim($crawler->filter('[data-counter="' . $counter . '"]')->text());
    }

    /** @return array<string, string> every number of the boxes */
    private function countersIn(Crawler $crawler): array
    {
        $counters = [];
        $crawler->filter('[data-counter]')->each(static function (Crawler $node) use (&$counters): void {
            $counters[(string) $node->attr('data-counter')] = trim($node->text());
        });

        return $counters;
    }
}
