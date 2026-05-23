<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use Doctrine\ORM\EntityManagerInterface;
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
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

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

        self::assertResponseRedirects('/case/' . $case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertSame('4521/302/2026', $refreshed->getCourtCaseNumber());
        self::assertTrue($refreshed->isPortalMonitoringActive());
        self::assertSame(CaseStatus::DOSAR_INREGISTRAT, $refreshed->getStatus());
    }

    public function testActivateRejectsInvalidFormat(): void
    {
        $this->client->loginUser($this->user);
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);

        $token = $this->activateToken($case);
        $this->client->request('POST', '/case/' . $case->getId() . '/portal/activate', [
            'portal_activate' => ['courtCaseNumber' => 'not-a-number', '_token' => $token],
        ]);

        self::assertResponseRedirects('/case/' . $case->getId());

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

        self::assertResponseRedirects('/case/' . $case->getId());

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

        self::assertResponseRedirects('/case/' . $case->getId());
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

        self::assertResponseRedirects('/case/' . $case->getId());
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
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);

        parent::tearDown();
    }
}
