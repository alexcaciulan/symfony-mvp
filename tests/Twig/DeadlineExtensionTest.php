<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use App\Service\Deadline\DeadlineConsequenceResolver;
use App\Twig\DeadlineExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The arrears rule is shown in three places that sit on the same screen: the
 * dashboard card, the list under it and the navigation badge. They read the same
 * rule from here, so a past-due row can never be labelled as missed while the
 * counter above it leaves it out.
 */
class DeadlineExtensionTest extends TestCase
{
    private DeadlineExtension $extension;

    protected function setUp(): void
    {
        // Stubs, not mocks: the rule under test reads neither the session nor the
        // database, and a mock without expectations is reported as a test smell.
        $this->extension = new DeadlineExtension(
            $this->createStub(Security::class),
            $this->createStub(LegalDeadlineRepository::class),
            new DeadlineConsequenceResolver(),
        );
    }

    #[DataProvider('arrearTypes')]
    public function testTypesWithASanctionCountAsArrears(DeadlineType $type): void
    {
        self::assertTrue($this->extension->isArrear($type));
    }

    /** @return iterable<string, array{DeadlineType}> */
    public static function arrearTypes(): iterable
    {
        yield 'stamp duty' => [DeadlineType::TIMBRARE];
        yield 'annulment application' => [DeadlineType::CERERE_IN_ANULARE];
        yield 'limitation' => [DeadlineType::PRESCRIPTIE];
        yield 'enforcement limitation' => [DeadlineType::PRESCRIPTIE_EXECUTARE];
        yield 'hearing' => [DeadlineType::JUDECATA];
        yield 'filing' => [DeadlineType::DEPUNERE_CERERE];
        yield 'reminder' => [DeadlineType::OTHER];
    }

    /**
     * The response term belongs to the debtor. Its expiry is what opens the filing
     * window, so reporting it as a missed obligation would announce a failure at the
     * exact moment the procedure moved forward.
     */
    public function testTheResponseTermOfTheDebtorIsNotAnArrear(): void
    {
        self::assertFalse($this->extension->isArrear(DeadlineType::RASPUNS_SOMATIE));
    }

    /**
     * Pins the agreement between the label and the counter: every type the arrears
     * aggregate leaves out must also be the type the list refuses to call missed.
     */
    public function testTheRuleAgreesWithTheTypesExcludedFromTheArrearsCount(): void
    {
        $excluded = (new DeadlineConsequenceResolver())->unsanctionedTypes();

        foreach (DeadlineType::cases() as $type) {
            self::assertSame(
                !in_array($type, $excluded, true),
                $this->extension->isArrear($type),
                sprintf('Type %s disagrees between the label and the arrears count.', $type->value),
            );
        }
    }
}
