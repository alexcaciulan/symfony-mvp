<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\BnrExchangeRate;
use App\Repository\BnrExchangeRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class BnrExchangeRateRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BnrExchangeRateRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(BnrExchangeRate::class);

        // Friday 2099-12-04 and the prior Friday; nothing on the weekend.
        $this->createRate('CHF', '2099-11-27', '4.9000');
        $this->createRate('CHF', '2099-12-04', '4.9500');
        $this->em->flush();
    }

    public function testReturnsRateForExactDate(): void
    {
        $rate = $this->repo->findRateValidAt('CHF', new \DateTimeImmutable('2099-12-04'));

        $this->assertNotNull($rate);
        $this->assertSame('4.9500', $rate->getRate());
    }

    public function testWeekendFallsBackToLastPublishedRate(): void
    {
        // Saturday 2099-12-05: no rate published, use Friday's.
        $rate = $this->repo->findRateValidAt('CHF', new \DateTimeImmutable('2099-12-05'));

        $this->assertNotNull($rate);
        $this->assertSame('4.9500', $rate->getRate());
        $this->assertEquals(new \DateTimeImmutable('2099-12-04'), $rate->getRateDate());
    }

    public function testDateBeforeAnyRateReturnsNull(): void
    {
        $rate = $this->repo->findRateValidAt('CHF', new \DateTimeImmutable('2099-01-01'));

        $this->assertNull($rate);
    }

    public function testUnknownCurrencyReturnsNull(): void
    {
        $rate = $this->repo->findRateValidAt('GBP', new \DateTimeImmutable('2099-12-04'));

        $this->assertNull($rate);
    }

    private function createRate(string $currency, string $date, string $rate): void
    {
        $entity = new BnrExchangeRate();
        $entity->setCurrency($currency);
        $entity->setRateDate(new \DateTimeImmutable($date));
        $entity->setRate($rate);
        $this->em->persist($entity);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM bnr_exchange_rate WHERE rate_date BETWEEN '2099-01-01' AND '2099-12-31'");
        parent::tearDown();
    }
}
