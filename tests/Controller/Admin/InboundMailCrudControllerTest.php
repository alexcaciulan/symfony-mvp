<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\FilingSubmission;
use App\Entity\InboundMail;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\InboundClassification;
use App\Enum\InboundMatchConfidence;
use App\Enum\InboundMatchMethod;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use App\Tests\Support\InboundTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The admin inbound queue: metadata only, read-only, a review can be closed. */
final class InboundMailCrudControllerTest extends WebTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;
    use InboundTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private User $admin;
    private LegalCase $case;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('app.uploads_dir');
        $this->user = $this->createFilingUser($this->em, 'inbound-admin-lawyer');
        $this->admin = $this->createFilingUser($this->em, 'inbound-admin');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $this->courtTransport()->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE entity_type IN (?, ?)', [LegalCase::class, 'App\Entity\InboundMail']);
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
            $this->removeFilingFixtures($this->em, $this->admin ?? null, null);
        }
        parent::tearDown();
    }

    public function testQueueShowsMetadataButNeverTheBody(): void
    {
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleFilingPart((int) $submission->getPart(1)?->getId());
        $part = $this->em->getRepository(FilingSubmission::class)->find($submission->getId())?->getPart(1);
        $mail = $this->receive($this->rawForPart($part, 'receipt-forged-auth'));

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/inbound-mail');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Confirmare de primire', $html, 'The subject is metadata.');
        self::assertStringContainsString('Verificare tehnică nereușită', $html);
        self::assertStringNotContainsString('Confirmăm primirea mesajului', $html, 'Never the body.');

        $this->client->request('GET', '/admin/inbound-mail/' . $mail->getId());
        self::assertResponseIsSuccessful();
        $detail = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Confirmăm primirea mesajului', $detail);
        self::assertStringNotContainsString('/download', $detail, 'No link to the stored copy.');

        $this->client->request('GET', '/admin/inbound-mail/' . $mail->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);
    }

    /** A receipt the lawyer uploaded is listed as such, not as an automatic court reply. */
    public function testAnUploadedReceiptIsNotListedAsAnAutomaticOne(): void
    {
        $submission = $this->submitFiling($this->case, $this->user);
        $now = new \DateTimeImmutable();
        $mail = new InboundMail(InboundMail::SOURCE_MANUAL, bin2hex(random_bytes(16)), 'inbound/test-manual.pdf', str_repeat('a', 64), 10, $now, $now);
        $mail->setRawMime(InboundMail::MIME_PDF);
        $mail->setClassification(InboundClassification::RECEIPT);
        $mail->match(null, $submission, InboundMatchMethod::MANUAL, InboundMatchConfidence::CERTAIN);
        $mail->markProcessed($now, null);
        $this->em->persist($mail);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/inbound-mail');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('tr[data-id="' . $mail->getId() . '"]');
        $text = $row->count() > 0 ? $row->text() : (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Confirmare de primire', $text);
        self::assertStringNotContainsString('Confirmare automată de primire', $text);

        $this->em->getConnection()->executeStatement('DELETE FROM inbound_mail WHERE id = ?', [$mail->getId()]);
    }

    public function testReviewIsClosedOnlyWithAValidToken(): void
    {
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleFilingPart((int) $submission->getPart(1)?->getId());
        $part = $this->em->getRepository(FilingSubmission::class)->find($submission->getId())?->getPart(1);
        $mail = $this->receive($this->rawForPart($part, 'receipt-forged-auth'));
        $this->client->loginUser($this->admin);

        $this->client->request('GET', sprintf('/admin/inbound-mail/%d/resolve?token=forged', $mail->getId()));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('PENDING', $this->reviewStatus((int) $mail->getId()));

        $crawler = $this->client->request('GET', '/admin/inbound-mail/' . $mail->getId());
        $this->client->click($crawler->filter('a.action-resolveReview')->link());
        self::assertResponseRedirects();
        self::assertSame('RESOLVED', $this->reviewStatus((int) $mail->getId()));
    }

    private function reviewStatus(int $id): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT review_status FROM inbound_mail WHERE id = ?', [$id]);
    }
}
