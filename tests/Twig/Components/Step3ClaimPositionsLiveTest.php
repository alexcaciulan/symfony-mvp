<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\User;
use App\Enum\RelationshipType;
use App\Util\ClaimRowLiveMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The positions table lives inside the component so editing a sum or a due date
 * recomputes the per-row interest and the totals without leaving the step. We
 * drive the same server round-trip a `data-model` change triggers: set the
 * `rows` prop to an edited state and assert the rendered totals follow.
 */
final class Step3ClaimPositionsLiveTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private User $user;

    protected function setUp(): void
    {
        static::createClient();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->user = new User();
        $this->user->setEmail('live-step3-' . uniqid() . '@test.com');
        $this->user->setPassword('x');
        $this->user->setIsVerified(true);
        $em->persist($this->user);
        $em->flush();

        // The form uses session-based CSRF; the component factory builds it
        // outside an HTTP request, so push a synthetic request with an
        // in-memory session for token storage.
        $stack = static::getContainer()->get(RequestStack::class);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack->push($request);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('DELETE FROM user WHERE email LIKE ?', ['live-step3-%']);
        parent::tearDown();
    }

    public function testEditingAPositionAmountRecomputesTheTotals(): void
    {
        $rows = ClaimRowLiveMapper::toArrays([
            $this->row('TES 0036', 200.0, '2024-05-01'),
            $this->row('TES 0038', 500.0, '2024-06-15'),
        ]);

        $component = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(currency: 'RON', relationshipType: RelationshipType::COMERCIAL),
            'rows' => $rows,
        ])->actingAs($this->user);

        $before = (string) $component->render();
        self::assertStringContainsString('700.00', $before, 'Sidebar principal is the sum of the two positions');

        // Edit the second position's amount, the way a data-model change would.
        $rows[1]['amount'] = '1.234,56';
        $after = (string) $component->set('rows', $rows)->render();

        // 200 + 1234.56 = 1434.56: the total followed the edit.
        self::assertStringContainsString('1,434.56', $after);
        self::assertStringNotContainsString('700.00', $after);
    }

    public function testExcludingAPositionDropsItFromTheTotal(): void
    {
        $rows = ClaimRowLiveMapper::toArrays([
            $this->row('TES 0036', 200.0, '2024-05-01'),
            $this->row('TES 0038', 500.0, '2024-06-15'),
        ]);

        $component = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(currency: 'RON', relationshipType: RelationshipType::COMERCIAL),
            'rows' => $rows,
        ])->actingAs($this->user);

        $rows[1]['excluded'] = true;
        $after = (string) $component->set('rows', $rows)->render();

        self::assertStringContainsString('200.00', $after);
        self::assertStringNotContainsString('700.00', $after);
    }

    private function row(string $number, float $amount, string $due): ClaimItemRow
    {
        return new ClaimItemRow(
            dedupKey: 'inv:' . $number,
            amount: $amount,
            currency: 'RON',
            documentNumber: $number,
            documentDate: new \DateTimeImmutable($due . ' -30 days'),
            dueDate: new \DateTimeImmutable($due),
            amountRon: $amount,
            confirmed: true,
        );
    }
}
