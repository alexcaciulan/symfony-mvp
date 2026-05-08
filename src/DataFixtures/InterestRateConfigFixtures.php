<?php

namespace App\DataFixtures;

use App\Entity\InterestRateConfig;
use App\Repository\InterestRateConfigRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

class InterestRateConfigFixtures extends Fixture implements FixtureGroupInterface
{
    private const RATES = [
        ['validFrom' => '2024-01-01', 'rate' => '7.00'],
        ['validFrom' => '2024-08-01', 'rate' => '6.50'],
        ['validFrom' => '2025-01-01', 'rate' => '6.50'],
        ['validFrom' => '2025-08-01', 'rate' => '6.00'],
        ['validFrom' => '2026-02-01', 'rate' => '6.00'],
    ];

    public function __construct(
        private InterestRateConfigRepository $repository,
    ) {}

    public static function getGroups(): array
    {
        return ['baseline'];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::RATES as $row) {
            $validFrom = new \DateTimeImmutable($row['validFrom']);

            if ($this->repository->findOneBy(['validFrom' => $validFrom]) !== null) {
                continue;
            }

            $config = new InterestRateConfig();
            $config->setValidFrom($validFrom);
            $config->setReferenceRate($row['rate']);
            $manager->persist($config);
        }

        $manager->flush();
    }
}
