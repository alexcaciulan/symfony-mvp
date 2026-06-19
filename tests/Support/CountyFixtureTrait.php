<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\County;
use App\Service\Court\LocalityNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Find-or-create a County for tests that persist a Court (county_id is NOT NULL).
 * Counties are reference data: they are not removed in tearDown, so repeated
 * lookups across tests reuse the same row without FK violations.
 */
trait CountyFixtureTrait
{
    /** @var array<string, County> */
    private array $countyFixtureCache = [];

    private function createCounty(EntityManagerInterface $em, string $name = 'Cluj'): County
    {
        $normalized = LocalityNormalizer::normalize($name) ?? $name;

        // Cache within the test: findOneBy does not see pending (unflushed) entities,
        // so a second call for the same county before flush would create a duplicate.
        if (isset($this->countyFixtureCache[$normalized])) {
            return $this->countyFixtureCache[$normalized];
        }

        $existing = $em->getRepository(County::class)->findOneBy(['normalizedName' => $normalized]);
        if ($existing !== null) {
            return $this->countyFixtureCache[$normalized] = $existing;
        }

        $county = new County();
        $county->setName($name);
        $county->setNormalizedName($normalized);
        $em->persist($county);

        return $this->countyFixtureCache[$normalized] = $county;
    }
}
