<?php

namespace App\Tests\Repository;

use App\Entity\County;
use App\Repository\CountyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CountyRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CountyRepository $repo;
    private string $prefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(County::class);
        $this->prefix = 'CtyTest-' . uniqid();
    }

    private function createCounty(string $name): County
    {
        $county = new County();
        $county->setName($this->prefix . '-' . $name);
        $county->setNormalizedName(mb_strtolower($this->prefix . '-' . $name));
        $this->em->persist($county);

        return $county;
    }

    public function testFindOneByNormalizedName(): void
    {
        $created = $this->createCounty('Cluj');
        $this->em->flush();

        $found = $this->repo->findOneByNormalizedName(mb_strtolower($this->prefix . '-Cluj'));

        $this->assertNotNull($found);
        $this->assertSame($created->getId(), $found->getId());
    }

    public function testFindOneByNormalizedNameReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->findOneByNormalizedName($this->prefix . '-nonexistent'));
    }

    public function testFindAllOrderedByName(): void
    {
        $this->createCounty('Zalau');
        $this->createCounty('Arad');
        $this->createCounty('Mures');
        $this->em->flush();

        $names = array_map(
            fn(County $c) => $c->getName(),
            array_values(array_filter(
                $this->repo->findAllOrdered(),
                fn(County $c) => str_starts_with($c->getName(), $this->prefix),
            )),
        );

        $this->assertSame(
            [$this->prefix . '-Arad', $this->prefix . '-Mures', $this->prefix . '-Zalau'],
            $names,
        );
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM city WHERE normalized_name LIKE 'ctytest-%'");
        $conn->executeStatement("DELETE FROM county WHERE name LIKE 'CtyTest-%'");
        parent::tearDown();
    }
}
