<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * Compares the signed revision of a PDF with the whole file, object by object, as
 * qpdf parses them. Objects compressed in object streams are compared the same
 * way as plain ones, which a byte scan of the appended update cannot do.
 *
 * Accepted after a signature: validation data (/DSS and the catalog entries a
 * signing tool updates with it) and, after a document timestamp, the timestamp's
 * own signature field and widget. Anything else that changes what a page shows,
 * an existing form field, or the catalog is a change to the signed document.
 * Stream contents are compared through their dictionaries only.
 */
final class PdfRevisionDiff
{
    /** Catalog entries a signing or validation tool may set; never page content. */
    private const CATALOG_KEYS_MAY_CHANGE = ['/DSS', '/Extensions', '/Metadata', '/Version'];
    /** Back-references into the page tree and the form, compared from the other side. */
    private const BACK_REFERENCES = ['/Parent', '/P'];
    private const MAX_DEPTH = 64;

    /** @var array<string, mixed> */
    private array $before;
    /** @var array<string, mixed> */
    private array $after;
    /** @var array<string, true> */
    private array $seen = [];

    /**
     * @param array<string, mixed> $before qpdf dump (acroform, pages, qpdf) of the signed revision
     * @param array<string, mixed> $after  the same for the whole file
     */
    private function __construct(array $before, array $after)
    {
        $this->before = is_array($before['qpdf'][1] ?? null) ? $before['qpdf'][1] : [];
        $this->after = is_array($after['qpdf'][1] ?? null) ? $after['qpdf'][1] : [];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function onlyValidationDataAdded(array $before, array $after, bool $requireDss, bool $allowSignatureFields): bool
    {
        return (new self($before, $after))->check($before['pages'] ?? [], $after['pages'] ?? [], $requireDss, $allowSignatureFields);
    }

    /**
     * @param list<array<string, mixed>> $pagesBefore
     * @param list<array<string, mixed>> $pagesAfter
     */
    private function check(array $pagesBefore, array $pagesAfter, bool $requireDss, bool $allowSignatureFields): bool
    {
        $catalogBefore = $this->resolve($this->before, $this->before['trailer']['value']['/Root'] ?? null);
        $catalogAfter = $this->resolve($this->after, $this->after['trailer']['value']['/Root'] ?? null);
        if (!is_array($catalogBefore) || !is_array($catalogAfter)) {
            return false;
        }
        if ($requireDss && !isset($catalogAfter['/DSS'])) {
            return false;
        }

        $skip = [...self::CATALOG_KEYS_MAY_CHANGE, '/AcroForm', '/Pages'];
        if (!$this->sameDictionary($catalogBefore, $catalogAfter, $skip)) {
            return false;
        }

        // Inherited page attributes (/Rotate, /MediaBox, /Resources) on the page tree root.
        $treeBefore = $this->resolve($this->before, $catalogBefore['/Pages'] ?? null);
        $treeAfter = $this->resolve($this->after, $catalogAfter['/Pages'] ?? null);
        if (!is_array($treeBefore) || !is_array($treeAfter) || !$this->sameDictionary($treeBefore, $treeAfter, ['/Kids'])) {
            return false;
        }

        if (count($pagesBefore) !== count($pagesAfter)) {
            return false;
        }
        foreach ($pagesBefore as $i => $page) {
            if (!$this->samePage($page['object'] ?? null, $pagesAfter[$i]['object'] ?? null, $allowSignatureFields)) {
                return false;
            }
        }

        return $this->sameForm(
            $this->resolve($this->before, $catalogBefore['/AcroForm'] ?? null),
            $this->resolve($this->after, $catalogAfter['/AcroForm'] ?? null),
            $allowSignatureFields,
        );
    }

    private function samePage(mixed $refBefore, mixed $refAfter, bool $allowSignatureFields): bool
    {
        $before = $this->resolve($this->before, $refBefore);
        $after = $this->resolve($this->after, $refAfter);
        if (!is_array($before) || !is_array($after) || !$this->sameDictionary($before, $after, ['/Annots'])) {
            return false;
        }

        $annotsBefore = $this->resolve($this->before, $before['/Annots'] ?? null) ?? [];
        $annotsAfter = $this->resolve($this->after, $after['/Annots'] ?? null) ?? [];
        if (!is_array($annotsBefore) || !is_array($annotsAfter)) {
            return false;
        }

        return $this->sameMembers($annotsBefore, $annotsAfter, fn (mixed $added): bool => $allowSignatureFields && $this->isSignatureWidget($added));
    }

    private function sameForm(mixed $before, mixed $after, bool $allowSignatureFields): bool
    {
        $before ??= [];
        $after ??= [];
        if (!is_array($before) || !is_array($after) || !$this->sameDictionary($before, $after, ['/Fields', '/SigFlags'])) {
            return false;
        }
        $fieldsBefore = $this->resolve($this->before, $before['/Fields'] ?? null) ?? [];
        $fieldsAfter = $this->resolve($this->after, $after['/Fields'] ?? null) ?? [];
        if (!is_array($fieldsBefore) || !is_array($fieldsAfter)) {
            return false;
        }

        return $this->sameMembers($fieldsBefore, $fieldsAfter, fn (mixed $added): bool => $allowSignatureFields && $this->fieldType($this->after, $added) === '/Sig');
    }

    /**
     * Every member of the earlier list is still there, unchanged, in the same
     * order; members added after it must pass $mayAdd.
     *
     * @param list<mixed>            $before
     * @param list<mixed>            $after
     * @param callable(mixed): bool $mayAdd
     */
    private function sameMembers(array $before, array $after, callable $mayAdd): bool
    {
        $before = array_values($before);
        $after = array_values($after);
        if (count($after) < count($before)) {
            return false;
        }
        foreach ($before as $i => $member) {
            if (!$this->same($member, $after[$i], 0)) {
                return false;
            }
        }
        foreach (array_slice($after, count($before)) as $added) {
            if (!$mayAdd($added)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param list<string>         $skip
     */
    private function sameDictionary(array $before, array $after, array $skip): bool
    {
        $skip = [...$skip, ...self::BACK_REFERENCES];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            if (!array_key_exists($key, $before) || !array_key_exists($key, $after) || !$this->same($before[$key], $after[$key], 0)) {
                return false;
            }
        }

        return true;
    }

    /** Deep equality across both object tables; a pair already under comparison counts as equal. */
    private function same(mixed $before, mixed $after, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }
        if (self::isRef($before) || self::isRef($after)) {
            $pair = (is_string($before) ? $before : '') . '|' . (is_string($after) ? $after : '');
            if (isset($this->seen[$pair])) {
                return true;
            }
            $this->seen[$pair] = true;

            return $this->same($this->resolve($this->before, $before), $this->resolve($this->after, $after), $depth + 1);
        }
        if (is_array($before) && is_array($after)) {
            if (array_is_list($before) !== array_is_list($after) || count($before) !== count($after)) {
                return false;
            }
            foreach ($before as $key => $value) {
                if (in_array($key, self::BACK_REFERENCES, true)) {
                    continue;
                }
                if (!array_key_exists($key, $after) || !$this->same($value, $after[$key], $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $before === $after;
    }

    /**
     * An object reference resolves to its value, a stream to its dictionary.
     *
     * @param array<string, mixed> $table
     */
    private function resolve(array $table, mixed $value): mixed
    {
        if (!self::isRef($value)) {
            return $value;
        }
        $entry = $table['obj:' . $value] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        return array_key_exists('stream', $entry) ? ['stream' => $entry['stream']['dict'] ?? null] : ($entry['value'] ?? null);
    }

    private function isSignatureWidget(mixed $annot): bool
    {
        $dict = $this->resolve($this->after, $annot);

        return is_array($dict) && ($dict['/Subtype'] ?? null) === '/Widget' && $this->fieldType($this->after, $annot) === '/Sig';
    }

    /** @param array<string, mixed> $table */
    private function fieldType(array $table, mixed $field): ?string
    {
        $node = $this->resolve($table, $field);
        for ($depth = 0; $depth < 32 && is_array($node); ++$depth) {
            if (isset($node['/FT'])) {
                return is_string($node['/FT']) ? $node['/FT'] : null;
            }
            $node = $this->resolve($table, $node['/Parent'] ?? null);
        }

        return null;
    }

    private static function isRef(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d+ \d+ R$/', $value) === 1;
    }
}
