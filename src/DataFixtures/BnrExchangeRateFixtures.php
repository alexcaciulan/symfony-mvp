<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\BnrExchangeRate;
use App\Repository\BnrExchangeRateRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * A handful of EUR anchor rates so the wizard preview and tests can convert
 * without hitting the network. `findRateValidAt` falls back to the most recent
 * anchor, so these cover the whole prescription window. Real daily rates come
 * from `app:import-exchange-rates`.
 */
class BnrExchangeRateFixtures extends Fixture implements FixtureGroupInterface
{
    private const RATES = [
        ['currency' => 'EUR', 'rateDate' => '2023-01-02', 'rate' => '4.9200'],
        ['currency' => 'EUR', 'rateDate' => '2024-01-02', 'rate' => '4.9745'],
        ['currency' => 'EUR', 'rateDate' => '2024-07-01', 'rate' => '4.9770'],
        ['currency' => 'EUR', 'rateDate' => '2025-01-02', 'rate' => '4.9760'],
        ['currency' => 'EUR', 'rateDate' => '2025-07-01', 'rate' => '4.9775'],
        ['currency' => 'EUR', 'rateDate' => '2026-01-02', 'rate' => '5.0000'],
    ];

    public function __construct(
        private BnrExchangeRateRepository $repository,
    ) {}

    public static function getGroups(): array
    {
        return ['baseline'];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::RATES as $row) {
            $rateDate = new \DateTimeImmutable($row['rateDate']);

            if ($this->repository->findOneBy(['currency' => $row['currency'], 'rateDate' => $rateDate]) !== null) {
                continue;
            }

            $rate = new BnrExchangeRate();
            $rate->setCurrency($row['currency']);
            $rate->setRateDate($rateDate);
            $rate->setRate($row['rate']);
            $manager->persist($rate);
        }

        $manager->flush();
    }
}
