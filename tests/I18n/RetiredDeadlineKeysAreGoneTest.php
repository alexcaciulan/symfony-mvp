<?php

declare(strict_types=1);

namespace App\Tests\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Copy of a feature that was removed outlives the feature very easily: the catalogue
 * keeps the keys, nothing renders them, and the next person to read the file takes the
 * wording for something the application still does. On this screen that is worse than
 * untidy, because the retired wording described legal effects the buttons no longer
 * have.
 *
 * Two halves, and both are needed. A key nobody renders is dead copy; a reference to a
 * key the catalogue no longer has renders the raw key name in front of a lawyer. So
 * each retired prefix has to be absent from both catalogues AND unreferenced by any
 * source file.
 */
final class RetiredDeadlineKeysAreGoneTest extends KernelTestCase
{
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->translator = static::getContainer()->get(TranslatorInterface::class);
    }

    /**
     * Retired key prefixes, grouped by what was removed. A prefix ending in a dot
     * covers the whole subtree; one without covers the single key.
     *
     * @return iterable<string, array{string}>
     */
    public static function retiredPrefixProvider(): iterable
    {
        // The button that dismissed a limitation term from the agenda, and the three
        // confirmation dialogs that stood in front of it. The period runs whether or
        // not the row is on screen, so the button only ever removed the warning.
        yield 'dismiss button' => ['deadlines.action.stop_tracking'];
        yield 'dismiss button note' => ['deadlines.action.note.stop_tracking'];
        yield 'dismiss dialog, limitation' => ['deadlines.confirm.limitation.'];
        yield 'dismiss dialog, filing interruption' => ['deadlines.confirm.filing_interruption.'];
        yield 'dismiss dialog, enforcement limitation' => ['deadlines.confirm.enforcement_limitation.'];

        // The action on the estimated summons row. There is no such row any more: the
        // term is not created until the service date is recorded, and the case is
        // carried by the blockage zone until then.
        yield 'estimated summons action' => ['deadlines.action.set_summons_communication_date'];
        yield 'confirm the service date' => ['case_overview.summons.alert_confirm_date'];

        // The uncertainty marker on a limitation row, replaced by the date the
        // interruption holds until.
        yield 'uncertainty marker' => ['deadlines.row.estimate.prescription_interruption.'];

        // The three fixed reminder chips, replaced by the ladder of the type: a
        // limitation term is announced at 30 and 14 days, not at 7 / 3 / 1.
        yield 'fixed 7-day chip' => ['case_overview.deadlines.alert_7_'];
        yield 'fixed 3-day chip' => ['case_overview.deadlines.alert_3_'];
        yield 'fixed 1-day chip' => ['case_overview.deadlines.alert_1_'];

        // The counters over the former page and the pills that filtered it, replaced by
        // the four boxes, which are the navigation between the tabs.
        yield 'pill counters' => ['deadlines.counter.'];
        yield 'pill counter hints' => ['deadlines.counter_hint'];
        yield 'pill counter labels' => ['deadlines.counter_aria'];
        yield 'filter clear' => ['deadlines.filter.clear'];
        yield 'filter counters note' => ['deadlines.filter.counters_note'];
        yield 'filter show completed' => ['deadlines.filter.show_completed'];

        // The side rail of limitation terms, now a tab of its own.
        yield 'limitation rail' => ['deadlines.rail'];

        // The former time buckets.
        yield 'bucket count' => ['deadlines.section.count'];
        yield 'bucket tomorrow' => ['deadlines.section.tomorrow'];
        yield 'bucket rest of week' => ['deadlines.section.rest_of_week'];
        yield 'bucket next 30 days' => ['deadlines.section.next30'];

        // The clean register banner, replaced by the sentence on top of the page.
        yield 'clean banner heading' => ['deadlines.clean.heading'];
        yield 'clean banner body' => ['deadlines.clean.body'];
        yield 'window line' => ['deadlines.window'];

        // The collapsible blockage zone, replaced by a tab.
        yield 'blockage zone heading' => ['deadlines.blockages.heading'];
        yield 'blockage zone disclosure' => ['deadlines.blockages.open'];

        // The buttons that generated a document straight from a row. One click spent a
        // subscription slot, so a row now only suggests the act.
        yield 'send summons button' => ['deadlines.action.send_summons'];
        yield 'payment order button' => ['deadlines.action.generate_payment_order'];
        yield 'filing button' => ['deadlines.action.confirm_filing'];

        // What a row used to print and the panel now states.
        yield 'principal on the row' => ['deadlines.row.principal'];
        yield 'unblocks filing on the row' => ['deadlines.row.unblocks_filing'];
        yield 'badge label' => ['deadlines.row.badge_aria'];
    }

    /**
     * The emails go out at seven and also after the term has passed, and the former copy
     * promised one on the day the term expires, which no job sends. A key-level test
     * cannot see a sentence changed inside a key that stayed.
     *
     * @param 'ro'|'en' $locale
     */
    #[DataProvider('localeProvider')]
    public function testTheAlertCopyNoLongerPromisesAnEmailOnTheDayOfExpiry(string $locale): void
    {
        foreach (['deadlines.empty.alerts_note'] as $key) {
            $text = $this->translator->trans($key, [], null, $locale);

            self::assertStringNotContainsString('în ziua expirării', $text, $key);
            self::assertStringNotContainsString('on the day', $text, $key);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function localeProvider(): iterable
    {
        yield 'ro' => ['ro'];
        yield 'en' => ['en'];
    }

    #[DataProvider('retiredPrefixProvider')]
    public function testNoCatalogueStillCarriesTheRetiredKeys(string $prefix): void
    {
        // The container hands out a decorator in this environment, so the catalogue is
        // reached through the bag contract rather than the concrete translator.
        self::assertInstanceOf(TranslatorBagInterface::class, $this->translator);

        foreach (['ro', 'en'] as $locale) {
            $found = array_values(array_filter(
                array_keys($this->translator->getCatalogue($locale)->all('messages')),
                static fn (string $key): bool => str_starts_with($key, $prefix),
            ));

            self::assertSame(
                [],
                $found,
                sprintf('messages.%s.yaml still carries copy for the removed "%s".', $locale, $prefix),
            );
        }
    }

    #[DataProvider('retiredPrefixProvider')]
    public function testNoSourceFileStillAsksForTheRetiredKeys(string $prefix): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path) {
            if (str_contains((string) file_get_contents($path), $prefix)) {
                $offenders[] = $path;
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf('"%s" is gone from the catalogues, so asking for it would render the key itself.', $prefix),
        );
    }

    /**
     * Every PHP and Twig file of the application. The translation files themselves are
     * left out on purpose: they are what the other test above reads, through the
     * compiled catalogue rather than as text.
     *
     * @return iterable<string>
     */
    private function sourceFiles(): iterable
    {
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');

        foreach (['/src', '/templates'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($projectDir . $dir, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && in_array($file->getExtension(), ['php', 'twig'], true)) {
                    yield $file->getPathname();
                }
            }
        }
    }
}
