<?php

namespace App\DataFixtures;

use App\Entity\Plan;
use App\Repository\PlanRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

class PlanFixtures extends Fixture implements FixtureGroupInterface
{
    private const PLANS = [
        [
            'name' => 'Trial',
            'priceMonthly' => '0.00',
            'includedCases' => 2,
            'pricePerExtra' => '0.00',
            'isTrial' => true,
        ],
        [
            'name' => 'Starter',
            'priceMonthly' => '99.00',
            'includedCases' => 5,
            'pricePerExtra' => '25.00',
            'isTrial' => false,
        ],
        [
            'name' => 'Pro',
            'priceMonthly' => '299.00',
            'includedCases' => 25,
            'pricePerExtra' => '15.00',
            'isTrial' => false,
        ],
    ];

    public function __construct(
        private PlanRepository $repository,
    ) {}

    public static function getGroups(): array
    {
        return ['baseline'];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::PLANS as $row) {
            if ($this->repository->findOneBy(['name' => $row['name']]) !== null) {
                continue;
            }

            $plan = new Plan();
            $plan->setName($row['name']);
            $plan->setPriceMonthly($row['priceMonthly']);
            $plan->setIncludedCases($row['includedCases']);
            $plan->setPricePerExtra($row['pricePerExtra']);
            $plan->setIsActive(true);
            $plan->setIsTrial($row['isTrial']);
            $manager->persist($plan);
        }

        $manager->flush();
    }
}
