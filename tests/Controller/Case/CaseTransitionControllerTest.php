<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\AuditLog;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\PersonType;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests for CaseTransitionController — manual workflow transitions from
 * the case overview page: register, issue ruling, reject, close (success
 * + insolvent dispatch). Covers happy paths, validation rejections,
 * status guards, CSRF, voter denial and Turbo Stream response detection.
 */
final class CaseTransitionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private Creditor $creditor;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('trans-ctrl-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Avocat');
        $this->user->setLastName('Transition');
        $this->em->persist($this->user);

        $this->creditor = new Creditor();
        $this->creditor->setUser($this->user);
        $this->creditor->setPersonType(PersonType::PJ);
        $this->creditor->setName('SC Transition Test SRL');
        $this->creditor->setAddress('Str. Test 1, București');
        $this->creditor->setCui('RO22223333');
        $this->em->persist($this->creditor);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM notification WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        parent::tearDown();
    }

    private function createCase(CaseStatus $status): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCreditor($this->creditor);
        $case->setStatus($status);
        $case->setAmount('3000.00');
        $case->setCurrency('RON');
        $this->em->persist($case);

        $debtor = new Debtor();
        $debtor->setLegalCase($case);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Debitor Transition SRL');
        $debtor->setAddress('Str. Test 2, București');
        $debtor->setCui('RO44445555');
        $this->em->persist($debtor);
        $case->addDebtor($debtor);

        $this->em->flush();

        return $case;
    }

    private function csrfForForm(LegalCase $case, string $formName): string
    {
        $this->client->request('GET', '/case/' . $case->getId());

        return (string) $this->client->getCrawler()
            ->filter('input[name="' . $formName . '[_token]"]')->first()->attr('value');
    }

    public function testRegisterHappyPathTransitionsToDosarInregistrat(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $token = $this->csrfForForm($case, 'register_case_number');

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/register', [
            'register_case_number' => ['courtCaseNumber' => '4521/302/2026', '_token' => $token],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::DOSAR_INREGISTRAT, $refreshed->getStatus());
        self::assertSame('4521/302/2026', $refreshed->getCourtCaseNumber());

        $entries = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_CASE_REGISTERED,
            'entityType' => LegalCase::class,
            'entityId' => (string) $refreshed->getId(),
        ]);
        self::assertCount(1, $entries);
        self::assertSame('4521/302/2026', $entries[0]->getNewData()['courtCaseNumber']);
        self::assertSame('CERERE_DEPUSA', $entries[0]->getNewData()['fromStatus']);
    }

    public function testRegisterRejectsInvalidFormat(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $token = $this->csrfForForm($case, 'register_case_number');

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/register', [
            'register_case_number' => ['courtCaseNumber' => 'abc/def/2026', '_token' => $token],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::CERERE_DEPUSA, $refreshed->getStatus(), 'Status must remain unchanged on validation failure.');
        self::assertNull($refreshed->getCourtCaseNumber());
    }

    public function testRegisterRejectsWrongStatus(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::AMIABIL);

        $token = $this->csrfForForm($case, 'register_case_number');

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/register', [
            'register_case_number' => ['courtCaseNumber' => '4521/302/2026', '_token' => $token],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::AMIABIL, $refreshed->getStatus());
        self::assertNull($refreshed->getCourtCaseNumber());
    }

    public function testIssueRulingHappyPathTransitionsToOrdonantaEmisa(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::TERMEN_FIXAT);

        $token = $this->csrfForForm($case, 'issue_ruling');
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/issue-ruling', [
            'issue_ruling' => ['rulingDate' => $today, '_token' => $token],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::ORDONANTA_EMISA, $refreshed->getStatus());
        self::assertNotNull($refreshed->getFinalRulingDate());
        self::assertSame($today, $refreshed->getFinalRulingDate()->format('Y-m-d'));

        $entries = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_RULING_ISSUED,
            'entityId' => (string) $refreshed->getId(),
        ]);
        self::assertCount(1, $entries);
    }

    public function testIssueRulingRejectsFutureDate(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::TERMEN_FIXAT);

        $token = $this->csrfForForm($case, 'issue_ruling');
        $future = (new \DateTimeImmutable('+5 days'))->format('Y-m-d');

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/issue-ruling', [
            'issue_ruling' => ['rulingDate' => $future, '_token' => $token],
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::TERMEN_FIXAT, $refreshed->getStatus());
        self::assertNull($refreshed->getFinalRulingDate());
    }

    public function testRejectHappyPathFromTermenFixat(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::TERMEN_FIXAT);

        $token = $this->csrfForForm($case, 'reject_case');

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/reject', [
            'reject_case' => [
                'reason' => 'NO_PROOF',
                'details' => 'Probe insuficiente conform sentinței.',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::RESPINSA, $refreshed->getStatus());

        $entries = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_CASE_REJECTED,
            'entityId' => (string) $refreshed->getId(),
        ]);
        self::assertCount(1, $entries);
        self::assertSame('respinge', $entries[0]->getNewData()['transition']);
        self::assertSame('NO_PROOF', $entries[0]->getNewData()['reason']);
        self::assertSame('TERMEN_FIXAT', $entries[0]->getNewData()['fromStatus']);
    }

    public function testRejectHappyPathFromInAnulare(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::IN_ANULARE);

        $token = $this->csrfForForm($case, 'reject_case');

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/reject', [
            'reject_case' => [
                'reason' => 'INADMISSIBLE',
                'details' => '',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::RESPINSA, $refreshed->getStatus());

        $entries = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_CASE_REJECTED,
            'entityId' => (string) $refreshed->getId(),
        ]);
        self::assertCount(1, $entries);
        self::assertSame('admite_cerere_anulare', $entries[0]->getNewData()['transition']);
    }

    public function testCloseDispatchPaidGoesToInchisSucces(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::DEFINITIVA);

        $token = $this->csrfForForm($case, 'close_case');

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/close', [
            'close_case' => [
                'reason' => 'PAID',
                'details' => 'Plata confirmată prin OP.',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::INCHIS_SUCCES, $refreshed->getStatus());

        $entries = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_CASE_CLOSED,
            'entityId' => (string) $refreshed->getId(),
        ]);
        self::assertCount(1, $entries);
        self::assertSame('inchide_succes', $entries[0]->getNewData()['transition']);
        self::assertSame('PAID', $entries[0]->getNewData()['reason']);
    }

    public function testCloseDispatchInsolventGoesToInchisPartialInsolvabil(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::DEFINITIVA);

        $token = $this->csrfForForm($case, 'close_case');

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/close', [
            'close_case' => [
                'reason' => 'INSOLVENT',
                'details' => '',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::INCHIS_PARTIAL_INSOLVABIL, $refreshed->getStatus());

        $entries = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_CASE_CLOSED,
            'entityId' => (string) $refreshed->getId(),
        ]);
        self::assertSame('inchide_insolvabil', $entries[0]->getNewData()['transition']);
    }

    public function testCloseDispatchPartialGoesToInchisSucces(): void
    {
        // PARTIAL ranks as a successful (partial) recovery — `targetTransition()`
        // returns `inchide_succes`. Non-obvious so we lock it in a dedicated test.
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::DEFINITIVA);

        $token = $this->csrfForForm($case, 'close_case');

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/close', [
            'close_case' => ['reason' => 'PARTIAL', 'details' => '', '_token' => $token],
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::INCHIS_SUCCES, $refreshed->getStatus());

        $entries = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_CASE_CLOSED,
            'entityId' => (string) $refreshed->getId(),
        ]);
        self::assertSame('inchide_succes', $entries[0]->getNewData()['transition']);
        self::assertSame('PARTIAL', $entries[0]->getNewData()['reason']);
    }

    public function testCloseDispatchAbandonedGoesToInchisInsolvabil(): void
    {
        // ABANDONED is the lawyer's decision to drop pursuit; treated as a
        // partial-insolvent closure for archival purposes.
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::DEFINITIVA);

        $token = $this->csrfForForm($case, 'close_case');

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/close', [
            'close_case' => ['reason' => 'ABANDONED', 'details' => '', '_token' => $token],
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::INCHIS_PARTIAL_INSOLVABIL, $refreshed->getStatus());

        $entries = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_CASE_CLOSED,
            'entityId' => (string) $refreshed->getId(),
        ]);
        self::assertSame('inchide_insolvabil', $entries[0]->getNewData()['transition']);
        self::assertSame('ABANDONED', $entries[0]->getNewData()['reason']);
    }

    public function testCloseRejectsWrongStatus(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::AMIABIL);

        $token = $this->csrfForForm($case, 'close_case');

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/close', [
            'close_case' => ['reason' => 'PAID', 'details' => '', '_token' => $token],
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::AMIABIL, $refreshed->getStatus());
    }

    public function testRegisterRespondsWithTurboStreamWhenAccepted(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $token = $this->csrfForForm($case, 'register_case_number');

        $this->client->request(
            'POST',
            '/case/' . $case->getId() . '/transition/register',
            ['register_case_number' => ['courtCaseNumber' => '4521/302/2026', '_token' => $token]],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringContainsString(
            'text/vnd.turbo-stream.html',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
        self::assertStringContainsString('case-status-badge', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('case-pipeline', (string) $this->client->getResponse()->getContent());
    }

    public function testCsrfMissingRejected(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $this->client->request('POST', '/case/' . $case->getId() . '/transition/register', [
            'register_case_number' => ['courtCaseNumber' => '4521/302/2026', '_token' => 'fake-token'],
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame(CaseStatus::CERERE_DEPUSA, $refreshed->getStatus());
        self::assertNull($refreshed->getCourtCaseNumber());
    }

    /**
     * @return iterable<string, array{string, CaseStatus, string, array<string, string>}>
     */
    public static function voterDenialCases(): iterable
    {
        yield 'register' => [
            'register',
            CaseStatus::CERERE_DEPUSA,
            'register_case_number',
            ['courtCaseNumber' => '4521/302/2026'],
        ];
        yield 'issue-ruling' => [
            'issue-ruling',
            CaseStatus::TERMEN_FIXAT,
            'issue_ruling',
            ['rulingDate' => '2026-05-01'],
        ];
        yield 'reject' => [
            'reject',
            CaseStatus::TERMEN_FIXAT,
            'reject_case',
            ['reason' => 'NO_PROOF', 'details' => ''],
        ];
        yield 'close' => [
            'close',
            CaseStatus::DEFINITIVA,
            'close_case',
            ['reason' => 'PAID', 'details' => ''],
        ];
    }

    /**
     * @param array<string, string> $payload
     */
    #[DataProvider('voterDenialCases')]
    public function testVoterDeniesOtherUserOnEveryTransition(
        string $routeSuffix,
        CaseStatus $status,
        string $formName,
        array $payload,
    ): void {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('trans-intruder-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $intruder->setFirstName('Intruder');
        $intruder->setLastName('Tester');
        $this->em->persist($intruder);

        $case = $this->createCase($status);
        $this->em->flush();

        try {
            $this->client->loginUser($intruder);
            $this->client->request('POST', '/case/' . $case->getId() . '/transition/' . $routeSuffix, [
                $formName => array_merge($payload, ['_token' => 'whatever']),
            ]);

            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $intruder->getId()]);
        }
    }

    public function testRecommendedActionsExposesIssueRulingButtonOnTermenFixat(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::TERMEN_FIXAT);

        $this->client->request('GET', '/case/' . $case->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('hs-modal-issue-ruling', $html);
        self::assertStringContainsString('hs-modal-reject', $html);
    }

    public function testTabAuditRendersEntriesAfterTransitions(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $token = $this->csrfForForm($case, 'register_case_number');
        $this->client->request('POST', '/case/' . $case->getId() . '/transition/register', [
            'register_case_number' => ['courtCaseNumber' => '4521/302/2026', '_token' => $token],
        ]);

        $this->client->request('GET', '/case/' . $case->getId());
        $html = (string) $this->client->getResponse()->getContent();

        // Tab Audit lists the audit entry with the category badge text ("case registered"
        // after `_` → space) plus the action label rendered as title-case ("Case Registered").
        self::assertStringContainsString('panel-audit', $html);
        self::assertStringContainsString('Case Registered', $html);
    }
}
