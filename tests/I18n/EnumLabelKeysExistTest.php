<?php

namespace App\Tests\I18n;

use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Enum\CourtType;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\NotificationChannel;
use App\Enum\PersonType;
use App\Enum\PortalEventType;
use App\Enum\RelationshipType;
use App\Enum\UserType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Defensive test: every enum case must have its label() key present in messages.ro.yaml.
 * Prevents silent drift between enum cases and the translation catalogue.
 */
class EnumLabelKeysExistTest extends KernelTestCase
{
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->translator = static::getContainer()->get(TranslatorInterface::class);
    }

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    #[DataProvider('enumProvider')]
    public function testAllEnumLabelKeysExistInRoCatalogue(string $enumClass): void
    {
        $this->assertTrue(method_exists($enumClass, 'label'), sprintf('%s must expose label()', $enumClass));

        $cases = $enumClass::cases();
        $this->assertNotEmpty($cases, sprintf('%s has no cases', $enumClass));

        $catalogue = $this->translator instanceof Translator
            ? $this->translator->getCatalogue('ro')
            : null;

        foreach ($cases as $case) {
            $key = $case->label();
            $this->assertNotEmpty($key, sprintf('%s::%s->label() returned empty', $enumClass, $case->name));

            $translated = $this->translator->trans($key, locale: 'ro');
            $this->assertNotSame(
                $key,
                $translated,
                sprintf('Translation key "%s" (from %s::%s) is missing in messages.ro.yaml', $key, $enumClass, $case->name),
            );

            if ($catalogue !== null) {
                $this->assertTrue(
                    $catalogue->has($key),
                    sprintf('Catalogue ro does not have "%s" (from %s::%s)', $key, $enumClass, $case->name),
                );
            }
        }
    }

    public static function enumProvider(): array
    {
        return [
            'CaseStatus'          => [CaseStatus::class],
            'CaseTransition'      => [CaseTransition::class],
            'CourtType'           => [CourtType::class],
            'DeadlinePriority'    => [DeadlinePriority::class],
            'DeadlineType'        => [DeadlineType::class],
            'DocumentType'        => [DocumentType::class],
            'ExtractionStatus'    => [ExtractionStatus::class],
            'NotificationChannel' => [NotificationChannel::class],
            'PersonType'          => [PersonType::class],
            'PortalEventType'     => [PortalEventType::class],
            'RelationshipType'    => [RelationshipType::class],
            'UserType'            => [UserType::class],
        ];
    }
}
