<?php

namespace App\Tests\I18n;

use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Enum\ClaimItemKind;
use App\Enum\CourtType;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Enum\DocumentType;
use App\Enum\ExtractionFailureReason;
use App\Enum\ExtractionMode;
use App\Enum\ExtractionPipeline;
use App\Enum\ExtractionStatus;
use App\Enum\InterestKind;
use App\Enum\NotificationChannel;
use App\Enum\NotificationType;
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

    /**
     * The two catalogues are kept in lockstep by convention, and a key added to
     * one only is invisible until someone switches locale. Checking the same
     * provider against `en` turns that into a test failure instead.
     *
     * @param class-string<\BackedEnum> $enumClass
     */
    #[DataProvider('enumProvider')]
    public function testAllEnumLabelKeysExistInEnCatalogue(string $enumClass): void
    {
        $catalogue = $this->translator instanceof Translator
            ? $this->translator->getCatalogue('en')
            : null;

        foreach ($enumClass::cases() as $case) {
            $key = $case->label();

            $this->assertNotSame(
                $key,
                $this->translator->trans($key, locale: 'en'),
                sprintf('Translation key "%s" (from %s::%s) is missing in messages.en.yaml', $key, $enumClass, $case->name),
            );

            if ($catalogue !== null) {
                $this->assertTrue(
                    $catalogue->has($key),
                    sprintf('Catalogue en does not have "%s" (from %s::%s)', $key, $enumClass, $case->name),
                );
            }
        }
    }

    /**
     * The upload dropdowns label their options with `document.type.*`, a second
     * catalogue namespace that `label()` does not cover. A type offered for
     * upload without a key there renders as the raw key in the wizard.
     *
     * @param 'ro'|'en' $locale
     */
    #[DataProvider('locales')]
    public function testEveryUploadableTypeHasAnUploadLabel(string $locale): void
    {
        foreach (DocumentType::uploadableTypes() as $type) {
            $key = 'document.type.' . $type->value;
            $this->assertNotSame(
                $key,
                $this->translator->trans($key, locale: $locale),
                sprintf('Upload label "%s" is missing in messages.%s.yaml', $key, $locale),
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        yield 'ro' => ['ro'];
        yield 'en' => ['en'];
    }

    public static function enumProvider(): array
    {
        return [
            'CaseStatus'          => [CaseStatus::class],
            'ClaimItemKind'       => [ClaimItemKind::class],
            'CaseTransition'      => [CaseTransition::class],
            'CourtType'           => [CourtType::class],
            'DeadlinePriority'    => [DeadlinePriority::class],
            'DeadlineType'        => [DeadlineType::class],
            'DocumentType'        => [DocumentType::class],
            'ExtractionFailureReason' => [ExtractionFailureReason::class],
            'ExtractionMode'      => [ExtractionMode::class],
            'ExtractionPipeline'  => [ExtractionPipeline::class],
            'ExtractionStatus'    => [ExtractionStatus::class],
            'InterestKind'        => [InterestKind::class],
            'NotificationChannel' => [NotificationChannel::class],
            'NotificationType'    => [NotificationType::class],
            'PersonType'          => [PersonType::class],
            'PortalEventType'     => [PortalEventType::class],
            'RelationshipType'    => [RelationshipType::class],
            'UserType'            => [UserType::class],
        ];
    }
}
