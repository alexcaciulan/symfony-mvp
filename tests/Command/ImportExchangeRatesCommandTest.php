<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ImportExchangeRatesCommand;
use App\Entity\BnrExchangeRate;
use App\Repository\BnrExchangeRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ImportExchangeRatesCommandTest extends KernelTestCase
{
    private const XML = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<DataSet xmlns="http://www.bnr.ro/xsd">
  <Header><Publisher>NBR</Publisher></Header>
  <Body>
    <Subject>Reference rates</Subject>
    <OrigCurrency>RON</OrigCurrency>
    <Cube date="2099-06-15">
      <Rate currency="EUR">4.9760</Rate>
      <Rate currency="USD" multiplier="100">457.91</Rate>
      <Rate currency="HUF" multiplier="100">1.2345</Rate>
    </Cube>
  </Body>
</DataSet>
XML;

    private EntityManagerInterface $em;
    private BnrExchangeRateRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(BnrExchangeRate::class);
    }

    public function testImportsFilteredCurrenciesWithMultiplierNormalized(): void
    {
        $tester = $this->runImport(self::XML);

        $this->assertStringContainsString('2 created', $tester->getDisplay());

        $eur = $this->repo->findRateValidAt('EUR', new \DateTimeImmutable('2099-06-15'));
        $this->assertNotNull($eur);
        $this->assertSame('4.9760', $eur->getRate());

        // USD had multiplier=100 → normalized to RON per single unit.
        $usd = $this->repo->findRateValidAt('USD', new \DateTimeImmutable('2099-06-15'));
        $this->assertNotNull($usd);
        $this->assertSame('4.5791', $usd->getRate());

        // HUF is outside the supported set and must be skipped.
        $this->assertNull($this->repo->findRateValidAt('HUF', new \DateTimeImmutable('2099-06-15')));
    }

    public function testRerunSkipsAndUpdateOverwrites(): void
    {
        $this->runImport(self::XML);

        // Second run without --update: nothing changes.
        $tester = $this->runImport(self::XML);
        $this->assertStringContainsString('0 created, 0 updated, 2 skipped', $tester->getDisplay());

        // --update with a changed EUR value overwrites.
        $changed = str_replace('<Rate currency="EUR">4.9760</Rate>', '<Rate currency="EUR">5.1234</Rate>', self::XML);
        $tester = $this->runImport($changed, ['--update' => true]);
        $this->assertStringContainsString('2 updated', $tester->getDisplay());

        $this->em->clear();
        $eur = $this->repo->findRateValidAt('EUR', new \DateTimeImmutable('2099-06-15'));
        $this->assertSame('5.1234', $eur->getRate());
    }

    public function testHttpFailureReturnsFailure(): void
    {
        $command = new ImportExchangeRatesCommand(
            $this->em,
            $this->repo,
            new MockHttpClient(new MockResponse('', ['http_code' => 500])),
        );
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Failed to fetch', $tester->getDisplay());
    }

    public function testInvalidXmlReturnsFailure(): void
    {
        $tester = $this->runImport('not valid xml at all');

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid XML', $tester->getDisplay());
    }

    /** @param array<string, mixed> $input */
    private function runImport(string $xml, array $input = []): CommandTester
    {
        $command = new ImportExchangeRatesCommand(
            $this->em,
            $this->repo,
            new MockHttpClient(new MockResponse($xml)),
        );
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM bnr_exchange_rate WHERE rate_date = '2099-06-15'");
        parent::tearDown();
    }
}
