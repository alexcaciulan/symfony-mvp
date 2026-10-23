<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Repository\CourtEmailAddressReportRepository;
use App\Service\Court\CourtEmailAddressService;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use App\Tests\Support\InboundTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The lawyer's side of the received messages: the receipt upload, the download of
 * a stored message, and what the card shows (never rendered HTML).
 */
final class CaseFilingReceiptUploadTest extends WebTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;
    use InboundTestTrait;

    private const TURBO = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private int $submissionId;
    /** @var list<User> */
    private array $otherUsers = [];
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'inbound-web');
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $this->courtTransport()->reset();
        $submission = $this->submitFiling($this->case, $this->user);
        $this->submissionId = (int) $submission->getId();
        $this->handleFilingPart((int) $submission->getPart(1)?->getId());
        static::getContainer()->get('security.token_storage')->setToken(null);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        if (isset($this->em)) {
            $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?', [LegalCase::class, (string) $this->case->getId()]);
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
            foreach ($this->otherUsers as $other) {
                $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$other->getId()]);
                $this->removeFilingFixtures($this->em, $other, null);
            }
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function acceptedFiles(): iterable
    {
        yield 'PDF' => ['confirmare.pdf', 'pdf'];
        yield 'PNG' => ['confirmare.png', 'png'];
        yield '.eml' => ['confirmare.eml', 'eml'];
    }

    #[DataProvider('acceptedFiles')]
    public function testUploadedReceiptIsRecordedAsTheLawyers(string $name, string $kind): void
    {
        $bytes = match ($kind) {
            'pdf' => self::pdfBytes(['Confirmare de primire a registraturii']),
            'png' => self::pngBytes(40, 30),
            default => self::corpus('justro-receipt'),
        };

        $this->upload($this->file($name, $bytes));

        self::assertResponseIsSuccessful();
        self::assertSame('RECEIPT_MANUAL', $this->evidence());
        $row = $this->em->getConnection()->fetchAssociative('SELECT match_method, match_confidence, classification, uploaded_by_id FROM inbound_mail WHERE matched_submission_id = ?', [$this->submissionId]);
        self::assertSame(['match_method' => 'MANUAL', 'match_confidence' => 'CERTAIN', 'classification' => 'RECEIPT', 'uploaded_by_id' => $this->user->getId()], $row);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT filed_at FROM legal_case WHERE id = ?', [$this->case->getId()]), 'Nothing is read from the file.');
    }

    public function testUploadedEmlProposesTheDateOfItsHeaderNotTheUpload(): void
    {
        $sentAt = $this->em->getRepository(FilingSubmission::class)->find($this->submissionId)?->getSentAt();
        self::assertNotNull($sentAt);
        // The court's server reports in UTC; the upload itself happens later.
        $answeredAt = $sentAt->modify('+40 seconds')->setTimezone(new \DateTimeZone('UTC'));
        $raw = (string) preg_replace('/^Date: .*$/m', 'Date: ' . $answeredAt->format(\DateTimeInterface::RFC2822), self::corpus('justro-receipt'));

        $this->upload($this->file('confirmare.eml', $raw));

        self::assertSame('RECEIPT_MANUAL', $this->evidence());
        $stored = (string) $this->em->getConnection()->fetchOne('SELECT court_receipt_at FROM filing_submission WHERE id = ?', [$this->submissionId]);
        self::assertSame($answeredAt->getTimestamp(), (new \DateTimeImmutable($stored))->getTimestamp());

        $this->client->request('GET', sprintf('/case/%d/filing/submission/card', $this->case->getId()));
        $local = $answeredAt->setTimezone(new \DateTimeZone('Europe/Bucharest'));
        self::assertSelectorTextContains('[data-testid="filing-receipt-manual-date"]', $local->format('d.m.Y H:i'));
        self::assertSelectorTextContains('[data-testid="filing-filed-date-proposal"]', $local->format('d.m.Y'));
        self::assertSame($local->format('Y-m-d'), $this->client->getCrawler()->filter('#filing-filed-at')->attr('value'));
    }

    public function testUploadedPdfProposesNoDateAndAsksForTheOneInTheDocument(): void
    {
        $this->upload($this->file('confirmare.pdf', self::pdfBytes(['Confirmare de primire a registraturii'])));

        self::assertSame('RECEIPT_MANUAL', $this->evidence());
        self::assertNull($this->em->getConnection()->fetchOne('SELECT court_receipt_at FROM filing_submission WHERE id = ?', [$this->submissionId]));

        $this->client->request('GET', sprintf('/case/%d/filing/submission/card', $this->case->getId()));
        self::assertSelectorTextContains('[data-testid="filing-receipt-manual"]', 'Confirmare încărcată de avocat la');
        self::assertSelectorTextContains('[data-testid="filing-receipt-manual-no-date"]', 'se introduce manual');
        self::assertSelectorNotExists('[data-testid="filing-filed-date-proposal"]');
        self::assertSame('', (string) $this->client->getCrawler()->filter('#filing-filed-at')->attr('value'));
    }

    public function testUploadedEmlDatedBeforeTheSendingProposesNoDate(): void
    {
        $raw = (string) preg_replace('/^Date: .*$/m', 'Date: Mon, 05 Jan 2026 09:00:00 +0000', self::corpus('justro-receipt'));

        $this->upload($this->file('confirmare.eml', $raw));

        self::assertSame('RECEIPT_MANUAL', $this->evidence());
        self::assertNull($this->em->getConnection()->fetchOne('SELECT court_receipt_at FROM filing_submission WHERE id = ?', [$this->submissionId]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedFiles(): iterable
    {
        yield 'Outlook .msg' => ['confirmare.msg', 'msg'];
        yield 'encrypted PDF' => ['confirmare.pdf', 'encrypted'];
        yield 'over 20 MB' => ['mare.pdf', 'oversize'];
    }

    #[DataProvider('refusedFiles')]
    public function testRefusedUploadStoresNothing(string $name, string $kind): void
    {
        $bytes = match ($kind) {
            'msg' => str_pad("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16) . "\x3E\0\x03\0\xFE\xFF\x09\0\x06\0", 1536, "\0"),
            'encrypted' => self::encryptPdf(self::pdfBytes(['Confirmare'])),
            default => '%PDF-1.4' . str_repeat('0', 21_000_000),
        };

        $this->upload($this->file($name, $bytes));

        self::assertResponseIsSuccessful();
        self::assertSame('AWAITING_RECEIPT', $this->evidence());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbound_mail WHERE matched_submission_id = ?', [$this->submissionId]));
        if ($kind === 'msg') {
            self::assertStringContainsString('.msg nu sunt acceptate', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testARefusedUploadAnswersWithAToastInPlace(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', sprintf('/case/%d/filing/submission/card', $this->case->getId()));
        self::assertSelectorExists('form[data-testid="filing-receipt-upload-form"]:not([data-turbo="false"])');

        $this->upload($this->file('confirmare.msg', str_pad("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 1536, "\0")));

        $response = $this->client->getResponse();
        self::assertFalse($response->isRedirect(), 'An error never reloads the page.');
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('target="toasts"', (string) $response->getContent());
    }

    public function testUploadsOverTheHourlyLimitAreRefusedAndStoreNothing(): void
    {
        $this->client->disableReboot();
        static::getContainer()->set('limiter.filing_signed_upload', new RateLimiterFactory(
            ['id' => 'filing_signed_upload', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'],
            new InMemoryStorage(),
        ));

        $this->upload($this->file('confirmare.pdf', self::pdfBytes(['Confirmare de primire'])));
        self::assertSame(1, $this->storedReceipts());
        $this->upload($this->file('confirmare2.pdf', self::pdfBytes(['A doua confirmare'])));

        self::assertStringContainsString(htmlspecialchars(static::getContainer()->get('translator')->trans('filing.receipt.upload.error.rate_limited'), ENT_QUOTES), (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->storedReceipts(), 'The upload over the limit stored nothing.');
    }

    public function testTheCourtTextIsShownToTheLawyerOfTheCaseOnly(): void
    {
        $part = $this->em->getRepository(FilingSubmission::class)->find($this->submissionId)?->getPart(1);
        $this->receive($this->rawForPart($part, 'human-reply'));
        $card = sprintf('/case/%d/filing/submission/card', $this->case->getId());

        $this->client->loginUser($this->user);
        $this->client->request('GET', $card);
        self::assertSelectorExists('[data-testid="filing-inbound-mail-excerpt"]');

        $admin = $this->otherUser(['ROLE_ADMIN']);
        $this->client->loginUser($admin);
        $this->client->request('GET', $card);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="filing-inbound-mail"]');
        self::assertSelectorNotExists('[data-testid="filing-inbound-mail-excerpt"]');
        $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="filing-inbound-mail-excerpt"]');
    }

    public function testOnlyAnUncertainAttributionIsLabelledAPossibleMatch(): void
    {
        $part = $this->em->getRepository(FilingSubmission::class)->find($this->submissionId)?->getPart(1);
        $certain = $this->receive($this->rawForPart($part, 'human-reply'));
        $this->em->getConnection()->executeStatement("UPDATE inbound_mail SET review_status = 'PENDING', review_reason = 'annex_bounce' WHERE id = ?", [$certain->getId()]);
        $this->em->clear();

        $this->client->loginUser($this->user);
        $this->client->request('GET', sprintf('/case/%d/filing/submission/card', $this->case->getId()));

        $translator = static::getContainer()->get('translator');
        self::assertSelectorTextContains('[data-testid="filing-inbound-mail-review"]', $translator->trans('filing.receipt.in_review_line'));
        self::assertStringNotContainsString($translator->trans('filing.receipt.probable_line'), (string) $this->client->getResponse()->getContent());

        $this->em->getConnection()->executeStatement("UPDATE inbound_mail SET review_reason = 'probable_match' WHERE id = ?", [$certain->getId()]);
        $this->client->request('GET', sprintf('/case/%d/filing/submission/card', $this->case->getId()));
        self::assertSelectorTextContains('[data-testid="filing-inbound-mail-review"]', $translator->trans('filing.receipt.probable_line'));
    }

    public function testUploadNeedsAValidCsrfToken(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', $this->uploadUrl(), ['_token' => 'forged'], ['receipt' => $this->file('c.pdf', self::pdfBytes(['x']))], self::TURBO);

        self::assertSame('AWAITING_RECEIPT', $this->evidence());
    }

    public function testAnotherLawyerCannotUpload(): void
    {
        $other = $this->otherUser();
        $this->client->loginUser($other);

        $this->client->request('POST', $this->uploadUrl(), ['_token' => 'x'], ['receipt' => $this->file('c.pdf', self::pdfBytes(['x']))]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('AWAITING_RECEIPT', $this->evidence());
    }

    public function testStoredMessageDownloadsForTheLawyerOnlyAndIsAudited(): void
    {
        $part = $this->em->getRepository(FilingSubmission::class)->find($this->submissionId)?->getPart(1);
        $mail = $this->receive($this->rawForPart($part, 'justro-receipt'));
        $url = sprintf('/case/%d/filing/inbound/%d/download', $this->case->getId(), $mail->getId());

        $this->client->loginUser($this->user);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame('message/rfc822', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'inbound_mail_downloaded' AND entity_id = ?", [(string) $this->case->getId()]));

        $admin = $this->otherUser(['ROLE_ADMIN']);
        $this->client->loginUser($admin);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->otherUser());
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCardShowsTheReceiptAsAProposalAndTheReceivedTextEscaped(): void
    {
        $part = $this->em->getRepository(FilingSubmission::class)->find($this->submissionId)?->getPart(1);
        $this->receive($this->rawForPart($part, 'justro-receipt'));
        $this->receive($this->rawForPart($part, 'human-reply'));

        $this->client->loginUser($this->user);
        $this->client->request('GET', sprintf('/case/%d/filing/submission/card', $this->case->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="filing-receipt-received"]', 'confirmare automată de primire');
        self::assertSelectorTextContains('[data-testid="filing-filed-date-proposal"]', 'propunere, necesită confirmarea avocatului');
        self::assertSelectorExists('[data-testid="filing-inbound-disclaimer"]');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT filed_at FROM legal_case WHERE id = ?', [$this->case->getId()]));
    }

    public function testCardOfAnUndeliveredFilingSaysItIsNotACourtRejection(): void
    {
        $part = $this->em->getRepository(FilingSubmission::class)->find($this->submissionId)?->getPart(1);
        $this->receive($this->rawForPart($part, 'exchange-ndr-5.1.1'));

        $this->client->loginUser($this->user);
        $this->client->request('GET', sprintf('/case/%d/filing/submission/card', $this->case->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="filing-submission-card"][data-status="UNDELIVERED"]');
        self::assertSelectorTextContains('[data-testid="filing-submission-undelivered"]', 'Nu este o respingere a instanței');
        self::assertSelectorTextContains('[data-testid="filing-submission-undelivered-deadline"]', 'NCC art. 2540');
        self::assertStringNotContainsString('art. 186', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('[data-testid="filing-submission-address-reported"]', 'raportată administratorului');
        self::assertSelectorTextContains('[data-testid="filing-submission-undelivered-diagnostic"]', '5.1.1');
        self::assertSelectorNotExists('[data-testid="filing-receipt-upload-form"]');

        $report = static::getContainer()->get(CourtEmailAddressReportRepository::class)->findOneBy(['submission' => $this->submissionId]);
        self::assertNotNull($report);
        static::getContainer()->get(CourtEmailAddressService::class)->resolveReport($report, $this->otherUser(['ROLE_ADMIN']), false);
        $this->client->request('GET', sprintf('/case/%d/filing/submission/card', $this->case->getId()));
        self::assertSelectorTextContains('[data-testid="filing-submission-address-reported"]', 'verificat de administrator');
        self::assertStringNotContainsString('blocată până atunci', (string) $this->client->getResponse()->getContent());
    }

    public function testLawyerAcknowledgesALateBounceFromTheCard(): void
    {
        $this->lateBounce();
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', sprintf('/case/%d/filing/submission/card', $this->case->getId()));
        self::assertSelectorExists('[data-testid="filing-submission-late-bounce"]');

        $this->client->submit($crawler->filter('[data-testid="filing-submission-late-bounce-ack"]')->form(), [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Verificarea a fost înregistrată.', (string) $this->client->getResponse()->getContent());
        self::assertSame('SENT', $this->delivery());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'filing_late_bounce_acknowledged' AND entity_id = ?", [(string) $this->case->getId()]));
    }

    public function testLateBounceAckNeedsAValidCsrfToken(): void
    {
        $this->lateBounce();
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->lateBounceAckUrl(), ['_token' => 'forged'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sesiunea formularului a expirat', (string) $this->client->getResponse()->getContent());
        self::assertSame('NEEDS_REVIEW', $this->delivery());
    }

    public function testAnotherLawyerCannotAcknowledgeALateBounce(): void
    {
        $this->lateBounce();
        $this->client->loginUser($this->otherUser());

        $this->client->request('POST', $this->lateBounceAckUrl(), ['_token' => 'x']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('NEEDS_REVIEW', $this->delivery());
    }

    /** A permanent bounce on the petition after the lawyer declared the filing date. */
    private function lateBounce(): void
    {
        $case = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        $case->setFiledAt(new \DateTimeImmutable('today'));
        $this->em->flush();
        $part = $this->em->getRepository(FilingSubmission::class)->find($this->submissionId)?->getPart(1);
        $this->receive($this->rawForPart($part, 'exchange-ndr-5.1.1'));
        self::assertSame('NEEDS_REVIEW', $this->delivery());
    }

    private function lateBounceAckUrl(): string
    {
        return sprintf('/case/%d/filing/submission/%d/late-bounce-ack', $this->case->getId(), $this->submissionId);
    }

    private function delivery(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT delivery_status FROM filing_submission WHERE id = ?', [$this->submissionId]);
    }

    private function upload(UploadedFile $file): void
    {
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_receipt_upload_' . $this->submissionId);
        $this->client->request('POST', $this->uploadUrl(), ['_token' => $token], ['receipt' => $file], self::TURBO);
    }

    private function storedReceipts(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM inbound_mail WHERE matched_submission_id = ? AND source = 'manual'", [$this->submissionId]);
    }

    private function uploadUrl(): string
    {
        return sprintf('/case/%d/filing/submission/%d/receipt-upload', $this->case->getId(), $this->submissionId);
    }

    private function file(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rcpt');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function evidence(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT evidence_status FROM filing_submission WHERE id = ?', [$this->submissionId]);
    }

    /** @param list<string> $roles */
    private function otherUser(array $roles = []): User
    {
        $other = $this->createFilingUser($this->em, 'inbound-web-other');
        $other->setRoles($roles);
        $this->em->flush();
        $this->otherUsers[] = $other;

        return $other;
    }

    private function csrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/case/' . $this->case->getId());
        $request = $this->client->getRequest();
        $stack = static::getContainer()->get(RequestStack::class);
        $stack->push($request);
        try {
            $token = static::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        } finally {
            $stack->pop();
        }
        $request->getSession()->save();

        return $token;
    }
}
