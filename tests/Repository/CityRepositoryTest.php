<?php

namespace App\Tests\Repository;

use App\Entity\City;
use App\Entity\County;
use App\Enum\UatType;
use App\Repository\CityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CityRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CityRepository $repo;
    private string $prefix;
    private County $county;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(City::class);
        $this->prefix = 'cityt' . substr(uniqid(), -8);

        $this->county = new County();
        $this->county->setName($this->prefix . '-County');
        $this->county->setNormalizedName($this->prefix . '-county');
        $this->em->persist($this->county);
    }

    private function createCity(string $name, ?UatType $type = null): City
    {
        $city = new City();
        $city->setCounty($this->county);
        $city->setName($this->prefix . '-' . $name);
        $city->setNormalizedName(mb_strtolower($this->prefix . '-' . $name));
        $city->setType($type);
        $this->em->persist($city);

        return $city;
    }

    public function testFindOneByCountyAndNormalizedName(): void
    {
        $created = $this->createCity('Cluj-Napoca', UatType::MUNICIPIU);
        $this->em->flush();

        $found = $this->repo->findOneByCountyAndNormalizedName(
            $this->county,
            mb_strtolower($this->prefix . '-Cluj-Napoca'),
        );

        $this->assertNotNull($found);
        $this->assertSame($created->getId(), $found->getId());
        $this->assertSame(UatType::MUNICIPIU, $found->getType());
    }

    public function testFindOneByCountyNameAndNormalizedNameJoinsCounty(): void
    {
        $created = $this->createCity('Dej');
        $this->em->flush();

        $found = $this->repo->findOneByCountyNameAndNormalizedName(
            $this->prefix . '-county',
            mb_strtolower($this->prefix . '-Dej'),
        );

        $this->assertNotNull($found);
        $this->assertSame($created->getId(), $found->getId());
    }

    public function testFindOneByCountyNameAndNormalizedNameReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->findOneByCountyNameAndNormalizedName(
            $this->prefix . '-county',
            $this->prefix . '-missing',
        ));
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM city WHERE normalized_name LIKE 'cityt%'");
        $conn->executeStatement("DELETE FROM county WHERE name LIKE 'cityt%'");
        parent::tearDown();
    }
}
