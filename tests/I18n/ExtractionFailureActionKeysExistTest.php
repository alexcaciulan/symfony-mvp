<?php

namespace App\Tests\I18n;

use App\Enum\ExtractionFailureReason;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Step 0 builds the action line from the failure reason at render time
 * (`wizard.step0.failure_action.<value>`), so a reason added without its copy
 * shows the lawyer a raw translation key next to a failed document instead of
 * what to do about it. label() alone is not enough: it names the cause, this
 * names the remedy.
 */
class ExtractionFailureActionKeysExistTest extends KernelTestCase
{
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->translator = static::getContainer()->get(TranslatorInterface::class);
    }

    #[DataProvider('localeProvider')]
    public function testEveryFailureReasonHasAnActionLine(string $locale): void
    {
        foreach (ExtractionFailureReason::cases() as $reason) {
            $key = 'wizard.step0.failure_action.' . $reason->value;

            $this->assertNotSame(
                $key,
                $this->translator->trans($key, locale: $locale),
                sprintf('Missing "%s" in messages.%s.yaml', $key, $locale),
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function localeProvider(): array
    {
        return ['ro' => ['ro'], 'en' => ['en']];
    }
}
