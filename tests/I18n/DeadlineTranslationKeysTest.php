<?php

declare(strict_types=1);

namespace App\Tests\I18n;

use App\Enum\BlockedCaseAlert;
use App\Enum\DeadlineBlockageReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the deadline copy against the two ways a catalogue rots.
 *
 * Keys are added when a screen is written and almost never removed when it changes, so
 * the catalogue silently accumulates sentences nothing prints. That matters more here
 * than elsewhere: this copy carries article numbers and states what a missed term costs,
 * a reviewer reads the YAML rather than the templates, and a paragraph that no longer
 * matches the behaviour is worse than one that was never written. Reviewing a text that
 * ships nowhere also wastes the only lawyer who reads them.
 *
 * Two complementary passes, because either alone has a blind spot. The reachability scan
 * resolves keys built by concatenation, which necessarily accepts everything under a
 * dynamic prefix such as `deadlines.blockage.`; the exactness pass closes exactly that
 * hole by pinning those families to the enum cases that generate them.
 */
final class DeadlineTranslationKeysTest extends TestCase
{
    /**
     * The namespaces this file owns. Everything a deadline screen prints lives under one
     * of the two, so the scan below covers the whole surface rather than a sample.
     */
    private const NAMESPACES = ['case_overview.deadlines.', 'deadlines.'];

    /** @var array<string, list<string>> */
    private static array $flattened = [];

    /** @var array<string, string> */
    private static array $sources = [];

    /**
     * Every key of the two namespaces is printed by something. A key that survives the
     * screen it was written for is dead copy: it reviews as if it shipped, and the next
     * person to change the behaviour has no way of telling it apart from live text.
     *
     * @param 'ro'|'en' $locale
     */
    #[DataProvider('locales')]
    public function testEveryDeadlineKeyIsReachableFromTheCode(string $locale): void
    {
        $source = self::source();
        $orphans = [];

        foreach (self::keysOf($locale) as $key) {
            if (!self::startsWithAny($key, self::NAMESPACES)) {
                continue;
            }
            if (!self::isReachable($key, $source)) {
                $orphans[] = $key;
            }
        }

        self::assertSame(
            [],
            $orphans,
            sprintf(
                "Orphan keys in messages.%s.yaml, referenced by nothing in src/ or templates/:\n%s",
                $locale,
                implode("\n", $orphans),
            ),
        );
    }

    /**
     * The blockage rows build their keys from the enum value
     * ({@see DeadlineBlockageReason::key()}), so the catalogue has to carry one family
     * per case and nothing else. A leftover family is invisible to the scan above, which
     * cannot see past the dynamic prefix, and a missing one prints the raw key into the
     * agenda where the lawyer is being told what a term costs him.
     *
     * @param 'ro'|'en' $locale
     */
    #[DataProvider('locales')]
    public function testTheBlockageFamiliesMatchTheirEnumExactly(string $locale): void
    {
        self::assertFamilies(
            $locale,
            'deadlines.blockage',
            array_map(static fn (DeadlineBlockageReason $r): string => $r->value, DeadlineBlockageReason::cases()),
            ['label', 'state', 'missing', 'legal_basis', 'cause', 'consequence', 'action'],
        );
    }

    /**
     * Same rule on the two catalogues the blocked-case alert reads from, the in-app
     * notification and the email. They are built from {@see BlockedCaseAlert} the same
     * way, and the email one is the copy that leaves the building.
     *
     * @param 'ro'|'en' $locale
     */
    #[DataProvider('locales')]
    public function testTheBlockedCaseAlertFamiliesMatchTheirEnumExactly(string $locale): void
    {
        $values = array_map(static fn (BlockedCaseAlert $a): string => $a->value, BlockedCaseAlert::cases());

        self::assertFamilies($locale, 'notification.blocked_case', $values, ['title', 'message']);
        self::assertFamilies($locale, 'email.blocked_case', $values, ['subject', 'heading', 'body', 'missing', 'action']);
    }

    /**
     * The accessors are the only thing that ever builds those keys, so they are asserted
     * against the catalogue directly: a rename inside an enum method would otherwise slip
     * past the family test above, which reconstructs the shape rather than reading it.
     */
    public function testTheEnumAccessorsProduceKeysThatExist(): void
    {
        $ro = self::keysOf('ro');

        foreach (DeadlineBlockageReason::cases() as $reason) {
            foreach ([$reason->label(), $reason->stateLabel(), $reason->missingDeadlineLabel(), $reason->legalBasisLabel(), $reason->causeLabel(), $reason->consequenceLabel(), $reason->actionLabel()] as $key) {
                self::assertContains($key, $ro, sprintf('%s is produced by DeadlineBlockageReason::%s but is not in the catalogue.', $key, $reason->name));
            }
        }

        foreach (BlockedCaseAlert::cases() as $alert) {
            foreach ([$alert->titleKey(), $alert->messageKey(), $alert->emailSubjectKey(), $alert->emailHeadingKey(), $alert->emailBodyKey(), $alert->emailMissingKey(), $alert->emailActionKey()] as $key) {
                self::assertContains($key, $ro, sprintf('%s is produced by BlockedCaseAlert::%s but is not in the catalogue.', $key, $alert->name));
            }
        }
    }

    /**
     * The two catalogues are kept in lockstep by convention. A key removed from one only
     * is the shape a half-done cleanup takes, and it stays invisible until someone
     * switches locale.
     */
    public function testTheTwoCataloguesCarryTheSameDeadlineKeys(): void
    {
        $namespaces = [...self::NAMESPACES, 'notification.blocked_case.', 'email.blocked_case.'];
        $filter = static fn (array $keys): array => array_values(array_filter(
            $keys,
            static fn (string $key): bool => self::startsWithAny($key, $namespaces),
        ));

        $ro = $filter(self::keysOf('ro'));
        $en = $filter(self::keysOf('en'));

        self::assertSame([], array_values(array_diff($ro, $en)), 'Present in ro, missing from en.');
        self::assertSame([], array_values(array_diff($en, $ro)), 'Present in en, missing from ro.');
    }

    /** @return iterable<string, array{'ro'|'en'}> */
    public static function locales(): iterable
    {
        yield 'ro' => ['ro'];
        yield 'en' => ['en'];
    }

    /**
     * A namespace holds exactly one family per enum value, and each family holds exactly
     * the expected leaves.
     *
     * @param 'ro'|'en'    $locale
     * @param list<string> $expectedFamilies
     * @param list<string> $expectedLeaves
     */
    private static function assertFamilies(string $locale, string $namespace, array $expectedFamilies, array $expectedLeaves): void
    {
        $found = [];
        foreach (self::keysOf($locale) as $key) {
            if (!str_starts_with($key, $namespace . '.')) {
                continue;
            }
            $rest = explode('.', substr($key, strlen($namespace) + 1));
            // The namespace also holds shared labels that belong to no family
            // (`email.blocked_case.missing_label`), which have no second segment.
            if (count($rest) !== 2) {
                continue;
            }
            $found[$rest[0]][] = $rest[1];
        }

        self::assertEqualsCanonicalizing(
            $expectedFamilies,
            array_keys($found),
            sprintf('%s in messages.%s.yaml must carry one family per enum case, no more and no fewer.', $namespace, $locale),
        );

        foreach ($found as $family => $leaves) {
            self::assertEqualsCanonicalizing(
                $expectedLeaves,
                $leaves,
                sprintf('%s.%s in messages.%s.yaml carries the wrong set of keys.', $namespace, $family, $locale),
            );
        }
    }

    /**
     * Whether something in src/ or templates/ can produce this key. Three shapes, which
     * are the three the codebase actually uses:
     *
     * 1. the key written out in full;
     * 2. a concatenation prefix, a literal ending in `.` or `_` that the key extends
     *    (`'deadlines.blockage.' . $this->value . '.'`, `'deadlines.weekday.' ~ n`);
     * 3. a concatenation suffix, the key without its last segment written out as a
     *    literal while the last segment is appended separately (`$base . '.mark'`).
     */
    private static function isReachable(string $key, string $source): bool
    {
        if (str_contains($source, $key)) {
            return true;
        }

        foreach (self::concatenationPrefixes($source) as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        $segments = explode('.', $key);
        $leaf = array_pop($segments);

        return str_contains($source, implode('.', $segments)) && str_contains($source, "'." . $leaf . "'");
    }

    /**
     * Every literal in the code that ends where a computed segment begins, i.e. a quoted
     * string ending in `.` or `_` immediately followed by a concatenation operator, PHP's
     * `.` or Twig's `~`.
     *
     * @return list<string>
     */
    private static function concatenationPrefixes(string $source): array
    {
        static $prefixes = null;
        if ($prefixes !== null) {
            return $prefixes;
        }

        preg_match_all('/[\'"]([a-z0-9_]+(?:\.[a-z0-9_]+)*[._])[\'"]\s*[~.]/i', $source, $matches);

        return $prefixes = array_values(array_unique($matches[1]));
    }

    /** @param list<string> $prefixes */
    private static function startsWithAny(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every dotted key of a catalogue, leaves only.
     *
     * @param 'ro'|'en' $locale
     *
     * @return list<string>
     */
    private static function keysOf(string $locale): array
    {
        if (isset(self::$flattened[$locale])) {
            return self::$flattened[$locale];
        }

        $keys = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$keys): void {
            foreach ($node as $segment => $value) {
                $key = $prefix === '' ? (string) $segment : $prefix . '.' . $segment;
                if (is_array($value)) {
                    $walk($value, $key);
                } else {
                    $keys[] = $key;
                }
            }
        };
        $walk(Yaml::parseFile(self::projectDir() . '/translations/messages.' . $locale . '.yaml'), '');

        return self::$flattened[$locale] = $keys;
    }

    /** Everything the application could print a key from, as one string. */
    private static function source(): string
    {
        if (isset(self::$sources['all'])) {
            return self::$sources['all'];
        }

        $source = '';
        foreach (['src', 'templates'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                self::projectDir() . '/' . $directory,
                \FilesystemIterator::SKIP_DOTS,
            ));
            foreach ($files as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'twig'], true)) {
                    $source .= file_get_contents($file->getPathname()) . "\n";
                }
            }
        }

        return self::$sources['all'] = $source;
    }

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }
}
