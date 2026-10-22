<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Service\Inbound\FixtureInboundSource;
use App\Service\Inbound\Imap\ImapMailbox;
use App\Service\Inbound\Imap\ImapMailboxFactory;
use App\Service\Inbound\Imap\ImapSettings;
use App\Service\Inbound\ImapInboundSource;
use App\Service\Inbound\InboundMailSourceResolver;
use App\Service\Inbound\MailpitInboundSource;
use App\Service\Inbound\NullInboundSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The source adapters without any network: Mailpit against its recorded API
 * answers, IMAP against a stand-in mailbox, and the DSN that picks them.
 */
final class InboundSourcesTest extends TestCase
{
    private static function recorded(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/inbound/mailpit/' . $name);
    }

    public function testMailpitSearchesByRecipientExcludingProcessedAndTagsWhatItTook(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options['body'] ?? null];
            if (str_contains($url, '/api/v1/search')) {
                return new MockResponse(count($requests) === 1 ? self::recorded('search.json') : self::recorded('search-empty.json'));
            }
            if (str_ends_with($url, '/raw')) {
                return new MockResponse(self::recorded('raw.eml'));
            }

            return new MockResponse('ok');
        });
        $source = MailpitInboundSource::fromDsn('mailpit://mailer:8025', $client, 'Depuneri@LexRecovery.test', 'lexrecovery.test', new NullLogger());

        $mails = [...$source->fetch(10)];

        self::assertCount(1, $mails);
        self::assertSame('iKxraPVJD7DGYkHzhHTD6Q', $mails[0]->sourceId);
        self::assertStringContainsString('To: depuneri@lexrecovery.test', $mails[0]->raw);
        $queries = [];
        foreach ($requests as [$method, $url]) {
            self::assertStringStartsWith('http://mailer:8025/api/v1/', $url, 'Only the Mailpit of the DSN is contacted.');
            if (str_contains($url, '/search')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $queries[] = $query['query'];
            }
        }
        self::assertSame(['to:"depuneri@lexrecovery.test" -tag:processed', 'to:"bounces+" to:"@lexrecovery.test" -tag:processed'], $queries);
        foreach ($queries as $query) {
            self::assertStringNotContainsString('from:', $query, 'Our own court emails leave from the platform address.');
        }

        $source->acknowledge($mails[0]);
        [$method, $url, $body] = end($requests);
        self::assertSame('PUT', $method);
        self::assertSame('http://mailer:8025/api/v1/tags', $url);
        self::assertSame(['IDs' => ['iKxraPVJD7DGYkHzhHTD6Q'], 'Tags' => ['processed']], json_decode((string) $body, true));
    }

    public function testMailpitWithAnEmptyOrMissingMailboxGivesNothing(): void
    {
        $empty = MailpitInboundSource::fromDsn('mailpit://mailer:8025', new MockHttpClient(static fn (): MockResponse => new MockResponse(self::recorded('search-empty.json'))), 'depuneri@lexrecovery.test', 'lexrecovery.test', new NullLogger());
        self::assertSame([], [...$empty->fetch(10)]);

        $missing = MailpitInboundSource::fromDsn('mailpit://mailer:8025', new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 404])), 'depuneri@lexrecovery.test', 'lexrecovery.test', new NullLogger());
        self::assertSame([], [...$missing->fetch(10)]);
    }

    public function testMailpitRefusesAHostThatIsNotLocal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailpitInboundSource::fromDsn('mailpit://mail.example.com:8025', new MockHttpClient(), 'depuneri@lexrecovery.test', 'lexrecovery.test', new NullLogger());
    }

    public function testResolverPicksTheAdapterFromTheDsn(): void
    {
        $factory = $this->createStub(ImapMailboxFactory::class);
        $resolver = static fn (string $dsn): InboundMailSourceResolver => new InboundMailSourceResolver($dsn, 'depuneri@lexrecovery.test', 'lexrecovery.test', new MockHttpClient(), $factory, new NullLogger(), '/app');

        self::assertInstanceOf(NullInboundSource::class, $resolver('')->resolve());
        self::assertInstanceOf(MailpitInboundSource::class, $resolver('mailpit://mailer:8025')->resolve());
        self::assertInstanceOf(FixtureInboundSource::class, $resolver('fixture://tests/fixtures/inbound')->resolve());
        $imap = $resolver('imaps://depuneri%40lexrecovery.test:secret@imap.example.test:993/INBOX?processed=Preluate')->resolve();
        self::assertInstanceOf(ImapInboundSource::class, $imap);
        self::assertSame('imap.example.test', $imap->settings()->host);
        self::assertSame(993, $imap->settings()->port);
        self::assertTrue($imap->settings()->tls);
        self::assertSame('depuneri@lexrecovery.test', $imap->settings()->username);
        self::assertSame('Preluate', $imap->settings()->processedFolder);

        $this->expectException(\InvalidArgumentException::class);
        $resolver('pop3://x')->resolve();
    }

    public function testImapConnectsOnlyOnFetchAndMovesWhatWasTaken(): void
    {
        $mailbox = new class implements ImapMailbox {
            /** @var list<string> */
            public array $moved = [];

            public function fetch(int $limit): array
            {
                return [['uid' => '17', 'raw' => "Subject: x\r\n\r\nx", 'date' => new \DateTimeImmutable('2026-10-22 10:00:00')]];
            }

            public function moveToProcessed(string $uid): void
            {
                $this->moved[] = $uid;
            }
        };
        $factory = $this->createMock(ImapMailboxFactory::class);
        $source = new ImapInboundSource(new ImapSettings('imap.example.test', 993, true, 'u', 'p', 'INBOX', 'Processed'), $factory);

        $factory->expects(self::once())->method('create')->willReturn($mailbox);
        $mails = [...$source->fetch(5)];
        $source->acknowledge($mails[0]);

        self::assertSame('17', $mails[0]->sourceId);
        self::assertSame(ImapInboundSource::NAME, $mails[0]->source);
        self::assertSame(['17'], $mailbox->moved);
    }

    public function testImapSourceBuiltFromTheDsnDoesNotConnect(): void
    {
        $factory = $this->createMock(ImapMailboxFactory::class);
        $factory->expects(self::never())->method('create');

        ImapInboundSource::fromDsn('imaps://u:p@imap.example.test/INBOX', $factory);
    }
}
