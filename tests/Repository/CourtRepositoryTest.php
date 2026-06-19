<?php

namespace App\Tests\Repository;

use App\Entity\Court;
use App\Enum\CourtType;
use App\Repository\CourtRepository;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CourtRepositoryTest extends KernelTestCase
{
    use CountyFixtureTrait;

    private EntityManagerInterface $em;
    private CourtRepository $repo;
    private string $testCounty;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(Court::class);
        $this->testCounty = 'TestCounty-' . uniqid();
    }

    private function createCourt(string $name, bool $active = true, ?string $county = null): Court
    {
        $court = new Court();
        $court->setName($name);
        $court->setCounty($this->createCounty($this->em, $county ?? $this->testCounty));
        $court->setType(CourtType::JUDECATORIE);
        $court->setActive($active);
        $this->em->persist($court);

        return $court;
    }

    public function testFindActiveByCountyFiltersCorrectly(): void
    {
        $this->createCourt('Judecatoria A');
        $this->createCourt('Judecatoria B');
        $this->createCourt('Judecatoria Inactive', false);
        $this->createCourt('Judecatoria Alt Judet', true, 'AltJudet-' . uniqid());
        $this->em->flush();

        $result = $this->repo->findActiveByCounty($this->testCounty);
        $this->assertCount(2, $result);
        foreach ($result as $court) {
            $this->assertTrue($court->isActive());
            $this->assertSame($this->testCounty, $court->getCounty()->getName());
        }
    }

    public function testFindActiveByCountyOrdersByNameAsc(): void
    {
        $this->createCourt('Zebra Court');
        $this->createCourt('Alpha Court');
        $this->createCourt('Middle Court');
        $this->em->flush();

        $result = $this->repo->findActiveByCounty($this->testCounty);
        $names = array_map(fn(Court $c) => $c->getName(), $result);
        $this->assertSame(['Alpha Court', 'Middle Court', 'Zebra Court'], $names);
    }

    public function testFindDistinctCountiesReturnsUniqueActiveCounties(): void
    {
        $county1 = 'DistinctTest-' . uniqid();
        $county2 = 'DistinctTest-' . uniqid();

        $this->createCourt('Court 1', true, $county1);
        $this->createCourt('Court 2', true, $county1); // same county
        $this->createCourt('Court 3', true, $county2);
        $this->createCourt('Court 4', false, 'InactiveCounty-' . uniqid()); // inactive
        $this->em->flush();

        $counties = $this->repo->findDistinctCounties();

        $this->assertContains($county1, $counties);
        $this->assertContains($county2, $counties);
        // Check uniqueness
        $this->assertSame(count($counties), count(array_unique($counties)));
    }

    public function testFindDistinctCountiesExcludesInactive(): void
    {
        $inactiveCounty = 'InactiveOnly-' . uniqid();
        $this->createCourt('Inactive Court', false, $inactiveCounty);
        $this->em->flush();

        $counties = $this->repo->findDistinctCounties();
        $this->assertNotContains($inactiveCounty, $counties);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $likePatterns = "co.name LIKE 'TestCounty-%' OR co.name LIKE 'DistinctTest-%' "
            . "OR co.name LIKE 'InactiveOnly-%' OR co.name LIKE 'InactiveCounty-%' OR co.name LIKE 'AltJudet-%'";
        $conn->executeStatement(
            "DELETE c FROM court c JOIN county co ON c.county_id = co.id WHERE " . $likePatterns
        );
        $conn->executeStatement(
            "DELETE co FROM county co WHERE co.name LIKE 'TestCounty-%' OR co.name LIKE 'DistinctTest-%' "
            . "OR co.name LIKE 'InactiveOnly-%' OR co.name LIKE 'InactiveCounty-%' OR co.name LIKE 'AltJudet-%'"
        );
        parent::tearDown();
    }
}
