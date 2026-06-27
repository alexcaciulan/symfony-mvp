<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\Court;
use App\Entity\CourtPortalEvent;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Enum\PersonType;
use App\Enum\PortalEventType;
use App\Service\Portal\PortalJustClient;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 6.1 — Tests for CasePortalController (activate + check-now).
 *
 * Cazurile de test NU au instanță cu portalCode → `monitorCase()` returnează
 * devreme, fără apel SOAP real către portal.just.ro.
 */
final class CasePortalControllerTest extends WebTestCase
{
    use CountyFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    /** @var int[] */
    private array $createdCourtIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail('portal-ctrl-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Portal');
        $this->user->setLastName('Owner');
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createCase(CaseStatus $status, bool $monitoringActive = false, ?string $courtCaseNumber = null): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setAmount('5000.00');
        $case->setCurrency('RON');
        $case->setStatus($status);
        $case->setPortalMonitoringActive($monitoringActive);
        $case->setCourtCaseNumber($courtCaseNumber);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    private function activateToken(LegalCase $case): string
    {
        $this->client->request('GET', '/case/' . $case->getId());

        return (string) $this->client->getCrawler()
            ->filter('input[name="portal_activate[_token]"]')->first()->attr('value');
    }

    private function checkNowToken(LegalCase $case): string
    {
        $this->client->request('GET', '/case/' . $case->getId());

        return (string) $this->client->getCrawler()
            ->filter('form[action*="check-now"] input[name="_token"]')->first()->attr('value');
    }

    public function testActivateHappyPathSetsNumberAndRegistersCase(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $token = $this->activateToken($case);
        $this->client->request('POST', '/case/' . $case->getId() . '/portal/activate', [
            'portal_activate' => ['courtCaseNumber' => '4521/302/2026', '_token' => $token],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId() . '?tab=portal');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame('4521/302/2026', $refreshed->getCourtCaseNumber());
        self::assertTrue($refreshed->isPortalMonitoringActive());
        self::assertSame(CaseStatus::DOSAR_INREGISTRAT, $refreshed->getStatus());
    }

    public function testActivateReturnsTurboStreamWhenRequested(): void
    {
        $this->client->loginUser($this->user);
        // No court → the post-activation sync returns early (no SOAP).
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $token = $this->activateToken($case);
        $this->client->request(
            'POST',
            '/case/' . $case->getId() . '/portal/activate',
            ['portal_activate' => ['courtCaseNumber' => '4521/302/2026', '_token' => $token]],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'text/vnd.turbo-stream.html',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
        $body = (string) $this->client->getResponse()->getContent();
        // Full-region update: hero + pipeline + tabs + portal panel + toast.
        self::assertStringContainsString('target="case-hero"', $body);
        self::assertStringContainsString('target="panel-portal"', $body);
        self::assertStringContainsString('target="toasts"', $body);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame('4521/302/2026', $refreshed->getCourtCaseNumber());
        self::assertSame(CaseStatus::DOSAR_INREGISTRAT, $refreshed->getStatus());
    }

    public function testActivateRejectionReturnsToastStreamOnlyWhenRequested(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createDiscoverableCase('SC Debitor Stream SRL');
        $case->setCourtCaseNumber('1000/300/2026');
        $this->em->flush();

        $token = $this->activateToken($case);
        $this->addPortalEvent($case->getId());

        $this->client->request(
            'POST',
            '/case/' . $case->getId() . '/portal/activate',
            ['portal_activate' => ['courtCaseNumber' => '2000/300/2026', '_token' => $token]],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        // Rejection: only a toast, no region replace (nothing changed).
        self::assertStringContainsString('target="toasts"', $body);
        self::assertStringNotContainsString('target="case-hero"', $body);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame('1000/300/2026', $refreshed->getCourtCaseNumber());
    }

    public function testActivateRejectsInvalidFormat(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $token = $this->activateToken($case);
        $this->client->request('POST', '/case/' . $case->getId() . '/portal/activate', [
            'portal_activate' => ['courtCaseNumber' => 'not-a-number', '_token' => $token],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId() . '?tab=portal');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertNull($refreshed->getCourtCaseNumber());
        self::assertFalse($refreshed->isPortalMonitoringActive());
        self::assertSame(CaseStatus::CERERE_DEPUSA, $refreshed->getStatus());
    }

    public function testActivateRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $this->client->request('POST', '/case/' . $case->getId() . '/portal/activate', [
            'portal_activate' => ['courtCaseNumber' => '4521/302/2026', '_token' => 'fake-token'],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId() . '?tab=portal');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertNull($refreshed->getCourtCaseNumber());
        self::assertFalse($refreshed->isPortalMonitoringActive());
    }

    public function testActivateForbiddenForNonOwner(): void
    {
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = new User();
        $other->setEmail('portal-intruder-' . uniqid() . '@test.com');
        $other->setPassword($hasher->hashPassword($other, 'password'));
        $other->setIsVerified(true);
        $this->em->persist($other);
        $this->em->flush();

        $this->client->loginUser($other);
        $this->client->request('POST', '/case/' . $case->getId() . '/portal/activate', [
            'portal_activate' => ['courtCaseNumber' => '4521/302/2026', '_token' => 'whatever'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$other->getId()]);
    }

    public function testCheckNowWarnsWhenCourtCaseNumberMissing(): void
    {
        $this->client->loginUser($this->user);
        // Monitorizare „activă" dar fără courtCaseNumber → guard flash_not_active.
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT, monitoringActive: true);

        $token = $this->checkNowToken($case);
        $this->client->request('POST', '/case/' . $case->getId() . '/portal/check-now', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/case/' . $case->getId() . '?tab=portal');
    }

    public function testCheckNowHappyPathWithoutCourtReturnsToOverview(): void
    {
        $this->client->loginUser($this->user);
        // Activă + courtCaseNumber, dar fără instanță → monitorCase returnează 0
        // fără apel SOAP. Verifică doar că ruta rulează și redirectează.
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT, monitoringActive: true, courtCaseNumber: '900/211/2026');

        $token = $this->checkNowToken($case);
        $this->client->request('POST', '/case/' . $case->getId() . '/portal/check-now', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/case/' . $case->getId() . '?tab=portal');
    }

    public function testCheckNowReturnsTurboStreamWhenRequested(): void
    {
        $this->client->loginUser($this->user);
        // Active + number but no court → monitorCase returns early (no SOAP).
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT, monitoringActive: true, courtCaseNumber: '900/211/2026');

        $token = $this->checkNowToken($case);
        $this->client->request(
            'POST',
            '/case/' . $case->getId() . '/portal/check-now',
            ['_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'text/vnd.turbo-stream.html',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="case-hero"', $body);
        self::assertStringContainsString('target="toasts"', $body);
    }

    private function discoverToken(LegalCase $case): string
    {
        $this->client->request('GET', '/case/' . $case->getId());

        return (string) $this->client->getCrawler()
            ->filter('form[action*="portal/discover"] input[name="_token"]')->first()->attr('value');
    }

    public function testDiscoverWithoutPortalCodeRendersFallback(): void
    {
        $this->client->loginUser($this->user);
        // Case has no court → discovery cannot run, renders the no_portal_code state.
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $token = $this->discoverToken($case);
        $this->client->request('POST', '/case/' . $case->getId() . '/portal/discover', [
            '_token' => $token,
        ]);

        self::assertResponseIsSuccessful();
        $translator = static::getContainer()->get('translator');
        self::assertStringContainsString(
            $translator->trans('case_overview.portal.discover_no_portal_code_title'),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testDiscoverRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $this->client->request('POST', '/case/' . $case->getId() . '/portal/discover', [
            '_token' => 'fake-token',
        ]);

        self::assertResponseIsSuccessful();
        $translator = static::getContainer()->get('translator');
        self::assertStringContainsString(
            $translator->trans('case_overview.portal.discover_error'),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * Builds a case with a court (portal code) + creditor + one debtor, so the
     * discovery guard passes and the matcher has parties to search by.
     */
    private function createDiscoverableCase(string $debtorName): LegalCase
    {
        $court = new Court();
        $court->setName('Judecatoria Discover ' . uniqid());
        $court->setType(CourtType::JUDECATORIE);
        $court->setCounty($this->createCounty($this->em, 'Cluj'));
        $court->setPortalCode('JudDISCOVER' . uniqid());
        $this->em->persist($court);

        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Creditor Discover SRL');
        $creditor->setAddress('Str. Test 1');
        $this->em->persist($creditor);

        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setAmount('5000.00');
        $case->setCurrency('RON');
        $case->setStatus(CaseStatus::CERERE_DEPUSA);
        $case->setCourt($court);
        $case->setCreditor($creditor);

        $debtor = new Debtor();
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName($debtorName);
        $debtor->setAddress('Str. Debitor 2');
        $case->addDebtor($debtor);

        $this->em->persist($case);
        $this->em->flush();

        $this->createdCourtIds[] = $court->getId();

        return $case;
    }

    public function testDiscoverRendersSuggestionsOnMatch(): void
    {
        $this->client->loginUser($this->user);
        $this->client->disableReboot();

        $case = $this->createDiscoverableCase('SC Debitor Discover SRL');

        // Fake portal client returns a matching case, so the real matcher scores
        // it and the controller renders the 'ok' state.
        $fakeClient = new class extends PortalJustClient {
            public function __construct()
            {
                parent::__construct(new NullLogger());
            }

            public function searchByParty(string $partyName, string $institutionCode, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
            {
                return [[
                    'numar' => '4521/302/2026',
                    'institutie' => $institutionCode,
                    'departament' => 'Civil',
                    'categorieCaz' => null,
                    'stadiuProcesual' => 'Fond',
                    'obiect' => 'ordonanță de plată',
                    'dataModificare' => '2026-03-01',
                    'parti' => [
                        ['nume' => 'CREDITOR DISCOVER SRL', 'calitateParte' => 'Creditor'],
                        ['nume' => 'DEBITOR DISCOVER SRL', 'calitateParte' => 'Debitor'],
                    ],
                    'sedinte' => [],
                    'caiAtac' => [],
                ]];
            }
        };
        static::getContainer()->set(PortalJustClient::class, $fakeClient);

        $token = $this->discoverToken($case);
        $this->client->request('POST', '/case/' . $case->getId() . '/portal/discover', [
            '_token' => $token,
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('4521/302/2026', (string) $this->client->getResponse()->getContent());
    }

    public function testDiscoverRendersErrorWhenMatcherThrows(): void
    {
        $this->client->loginUser($this->user);
        $this->client->disableReboot();

        $case = $this->createDiscoverableCase('SC Debitor Eroare SRL');

        // Non-PortalJustException escapes the matcher's catch and bubbles to the
        // controller, which renders the 'error' state.
        $fakeClient = new class extends PortalJustClient {
            public function __construct()
            {
                parent::__construct(new NullLogger());
            }

            public function searchByParty(string $partyName, string $institutionCode, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
            {
                throw new \RuntimeException('boom');
            }
        };
        static::getContainer()->set(PortalJustClient::class, $fakeClient);

        $token = $this->discoverToken($case);
        $this->client->request('POST', '/case/' . $case->getId() . '/portal/discover', [
            '_token' => $token,
        ]);

        self::assertResponseIsSuccessful();
        $translator = static::getContainer()->get('translator');
        self::assertStringContainsString(
            $translator->trans('case_overview.portal.discover_error'),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /** Persists a real portal event via a fresh EM, so the next request sees it. */
    private function addPortalEvent(int $caseId): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $case = $em->find(LegalCase::class, $caseId);
        $event = new CourtPortalEvent();
        $event->setLegalCase($case);
        $event->setEventType(PortalEventType::HEARING_COMPLETED);
        $event->setDescription('Test portal event');
        $event->setNotified(true);
        $em->persist($event);
        $em->flush();
    }

    public function testDiscoverLockedWhenActivityExists(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createDiscoverableCase('SC Debitor Lock SRL');

        // Grab the discover token while the form is still shown (no activity yet),
        // then introduce real activity so the backend guard locks the search.
        $token = $this->discoverToken($case);
        $this->addPortalEvent($case->getId());

        $this->client->request('POST', '/case/' . $case->getId() . '/portal/discover', [
            '_token' => $token,
        ]);

        self::assertResponseIsSuccessful();
        $translator = static::getContainer()->get('translator');
        self::assertStringContainsString(
            $translator->trans('case_overview.portal.discover_locked_title'),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testActivateRejectsNumberChangeWhenActivityExists(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createDiscoverableCase('SC Debitor Lock SRL');
        $case->setCourtCaseNumber('1000/300/2026');
        $this->em->flush();

        // Token while the editable form is still shown (no activity yet).
        $token = $this->activateToken($case);
        $this->addPortalEvent($case->getId());

        $this->client->request('POST', '/case/' . $case->getId() . '/portal/activate', [
            'portal_activate' => ['courtCaseNumber' => '2000/300/2026', '_token' => $token],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId() . '?tab=portal');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame('1000/300/2026', $refreshed->getCourtCaseNumber());
    }

    public function testActivateAllowsSameNumberWhenActivityExists(): void
    {
        $this->client->loginUser($this->user);
        $this->client->disableReboot();

        $case = $this->createDiscoverableCase('SC Debitor Same SRL');
        $case->setCourtCaseNumber('1000/300/2026');
        $this->em->flush();

        // Fake client so the post-activation sync does not hit the real portal.
        $fakeClient = new class extends PortalJustClient {
            public function __construct()
            {
                parent::__construct(new NullLogger());
            }

            public function searchByCaseNumber(string $caseNumber, string $institutionCode): array
            {
                return [];
            }
        };
        static::getContainer()->set(PortalJustClient::class, $fakeClient);

        // Token while the form is shown (no activity yet), then add activity.
        $token = $this->activateToken($case);
        $this->addPortalEvent($case->getId());
        $this->em->clear();

        // Re-confirming the SAME number is allowed even with activity present.
        $this->client->request('POST', '/case/' . $case->getId() . '/portal/activate', [
            'portal_activate' => ['courtCaseNumber' => '1000/300/2026', '_token' => $token],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId() . '?tab=portal');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame('1000/300/2026', $refreshed->getCourtCaseNumber());
        // Activation proceeded (not blocked): the case registered.
        self::assertSame(CaseStatus::DOSAR_INREGISTRAT, $refreshed->getStatus());
    }

    public function testDiscoverForbiddenForNonOwner(): void
    {
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = new User();
        $other->setEmail('portal-discover-intruder-' . uniqid() . '@test.com');
        $other->setPassword($hasher->hashPassword($other, 'password'));
        $other->setIsVerified(true);
        $this->em->persist($other);
        $this->em->flush();

        $this->client->loginUser($other);
        $this->client->request('POST', '/case/' . $case->getId() . '/portal/discover', [
            '_token' => 'whatever',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$other->getId()]);
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement(
            'DELETE n FROM notification n JOIN legal_case lc ON n.legal_case_id = lc.id WHERE lc.user_id = ?',
            [$userId]
        );
        $conn->executeStatement(
            'DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.user_id = ?',
            [$userId]
        );
        $conn->executeStatement(
            'DELETE cpe FROM court_portal_event cpe JOIN legal_case lc ON cpe.legal_case_id = lc.id WHERE lc.user_id = ?',
            [$userId]
        );
        $conn->executeStatement(
            'DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id WHERE lc.user_id = ?',
            [$userId]
        );
        $conn->executeStatement(
            'DELETE d FROM debtor d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.user_id = ?',
            [$userId]
        );
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = ?', [$userId]);
        foreach ($this->createdCourtIds as $courtId) {
            $conn->executeStatement('DELETE FROM court WHERE id = ?', [$courtId]);
        }
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);

        parent::tearDown();
    }
}
