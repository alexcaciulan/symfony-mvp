<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\DTO\Case\ClaimPositionsView;
use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\RelationshipType;
use App\Service\Case\OverviewContextBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class OverviewContextBuilderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->user = new User();
        $this->user->setEmail('overview-builder-' . uniqid() . '@test.com');
        $this->user->setPassword('x');
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Overview');
        $this->user->setLastName('Builder');
        $this->em->persist($this->user);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setStatus(CaseStatus::AMIABIL);
        $this->case->setRelationshipType(RelationshipType::COMERCIAL);
        $this->case->setAmount('3000.00');
        $this->case->setCurrency('RON');
        $this->em->persist($this->case);
        $this->em->flush();

        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->user, 'main', $this->user->getRoles()));
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('security.token_storage')->setToken(null);
        $conn = $this->em->getConnection();
        $id = $this->user->getId();
        $conn->executeStatement('DELETE FROM claim_item WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $id]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $id]);
        parent::tearDown();
    }

    public function testTheClaimCardReceivesThePositionsInDisplayOrder(): void
    {
        $late = $this->position('F-LATE', '1000.00', '-30 days');
        $old = $this->position('F-OLD', '2000.00', '-200 days');
        $this->em->flush();

        $context = static::getContainer()->get(OverviewContextBuilder::class)->build($this->case);

        self::assertInstanceOf(ClaimPositionsView::class, $context['claim_positions']);
        self::assertSame([$old->getId(), $late->getId()], array_map(static fn ($row) => $row->item->getId(), $context['claim_positions']->rows));
        self::assertNotNull($context['claim_positions']->rows[0]->accessoryTotal, 'The accessory comes from the case aggregate.');
        foreach (['claim_items', 'claim_items_all', 'claim_item_accessories', 'penalty_breakdown'] as $removed) {
            self::assertArrayNotHasKey($removed, $context, $removed);
        }
        self::assertArrayHasKey('interest_breakdown', $context);
        self::assertArrayHasKey('breakdown_error', $context);
    }

    public function testTheAddedPositionsAreMarkedOnTheirRow(): void
    {
        $this->position('F-1', '1000.00', '-30 days');
        $added = $this->position('F-2', '2000.00', '-20 days');
        $this->em->flush();

        $context = static::getContainer()->get(OverviewContextBuilder::class)->build($this->case, ['claim_added_item_ids' => [$added->getId()]]);

        $flags = [];
        foreach ($context['claim_positions']->rows as $row) {
            $flags[$row->item->getDocumentNumber()] = $row->added;
        }
        self::assertSame(['F-1' => false, 'F-2' => true], $flags);
    }

    private function position(string $number, string $amount, string $dueDate): ClaimItem
    {
        $item = new ClaimItem();
        $item->setDocumentNumber($number);
        $item->setAmount($amount);
        $item->setCurrency('RON');
        $item->setAmountRon($amount);
        $item->setDueDate(new \DateTimeImmutable($dueDate));
        $item->setConfirmedByLawyer(true);
        $item->setDedupKey('builder-' . uniqid('', true));
        $this->case->addClaimItem($item);
        $this->em->persist($item);

        return $item;
    }
}
