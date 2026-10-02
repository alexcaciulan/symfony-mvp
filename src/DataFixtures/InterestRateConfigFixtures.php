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
     *
     * History starts on 2011-09-01, when OG 13/2011 came into force; the level
     * then (6.25%) had held since 2010-05-05. Rows before 2022-08-08 come from
     * the BNR circulars in Monitorul Oficial, whose effective date ("începând cu
     * data de") is sometimes a day after the board decision.
     */
    private const RATES = [
        ['validFrom' => '2011-09-01', 'rate' => '6.25'],
        ['validFrom' => '2011-11-03', 'rate' => '6.00'],
        ['validFrom' => '2012-01-06', 'rate' => '5.75'],
        ['validFrom' => '2012-02-03', 'rate' => '5.50'],
        ['validFrom' => '2012-03-30', 'rate' => '5.25'],
        ['validFrom' => '2013-07-02', 'rate' => '5.00'],
        ['validFrom' => '2013-08-06', 'rate' => '4.50'],
        ['validFrom' => '2013-10-01', 'rate' => '4.25'],
        ['validFrom' => '2013-11-06', 'rate' => '4.00'],
        ['validFrom' => '2014-01-09', 'rate' => '3.75'],
        ['validFrom' => '2014-02-05', 'rate' => '3.50'],
        ['validFrom' => '2014-08-05', 'rate' => '3.25'],
        ['validFrom' => '2014-10-01', 'rate' => '3.00'],
        ['validFrom' => '2014-11-05', 'rate' => '2.75'],
        ['validFrom' => '2015-01-08', 'rate' => '2.50'],
        ['validFrom' => '2015-02-05', 'rate' => '2.25'],
        ['validFrom' => '2015-04-01', 'rate' => '2.00'],
        ['validFrom' => '2015-05-07', 'rate' => '1.75'],
        ['validFrom' => '2018-01-09', 'rate' => '2.00'],
        ['validFrom' => '2018-02-08', 'rate' => '2.25'],
        ['validFrom' => '2018-05-08', 'rate' => '2.50'],
        ['validFrom' => '2020-03-23', 'rate' => '2.00'],
        ['validFrom' => '2020-06-02', 'rate' => '1.75'],
        ['validFrom' => '2020-08-06', 'rate' => '1.50'],
        ['validFrom' => '2021-01-18', 'rate' => '1.25'],
        ['validFrom' => '2021-10-06', 'rate' => '1.50'],
        ['validFrom' => '2021-11-10', 'rate' => '1.75'],
        ['validFrom' => '2022-01-11', 'rate' => '2.00'],
        ['validFrom' => '2022-02-10', 'rate' => '2.50'],
        ['validFrom' => '2022-04-06', 'rate' => '3.00'],
        ['validFrom' => '2022-05-11', 'rate' => '3.75'],
        ['validFrom' => '2022-07-07', 'rate' => '4.75'],
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
