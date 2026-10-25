<?php

declare(strict_types=1);

namespace App\Service\Filing;

/**
 * A signed file whose pages carry every generated word in order. The inserted
 * words stay in memory for counting: they may hold the signer's or a party's
 * name, so they are never stored or logged.
 */
final readonly class PageComparisonResult
{
    /**
     * @param array<int, list<string>> $insertedByPage 1-based page number => words found only in the signed page
     */
    public function __construct(
        public array $insertedByPage,
        public int $extraPages,
    ) {}

    public function exact(): bool
    {
        return $this->insertedByPage === [] && $this->extraPages === 0;
    }

    public function insertedWordCount(): int
    {
        return array_sum(array_map(count(...), $this->insertedByPage));
    }
}
