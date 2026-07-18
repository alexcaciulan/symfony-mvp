<?php

namespace App\DataFixtures;

use App\Entity\InterestRateConfig;
use App\Repository\InterestRateConfigRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

class InterestRateConfigFixtures extends Fixture implements FixtureGroupInterface
{
    /**
     * BNR monetary policy rate = statutory reference rate (OG 13/2011 art. 3 para. 3).
     * validFrom is the effective date of each CA decision, not the meeting date.
     * The margin (+8 pp for B2B penalty interest) is applied at calculation time,
     * not stored here. Source: bnr.ro/1970-rata-dobanzii-de-politica-monetara.
     * The last rate carries forward, so a held level needs no repeated rows.
     */
    private const RATES = [
        ['validFrom' => '2022-08-08', 'rate' => '5.50'],
        ['validFrom' => '2022-10-06', 'rate' => '6.25'],
        ['validFrom' => '2022-11-09', 'rate' => '6.75'],
        ['validFrom' => '2023-01-11', 'rate' => '7.00'],
        ['validFrom' => '2024-07-08', 'rate' => '6.75'],
        ['validFrom' => '2024-08-08', 'rate' => '6.50'],
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
