<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\OpisPlacement;
use App\Service\Case\CaseAccessoryService;
use App\Service\Document\FileContentHasher;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Everything a built package depends on, as plain values. The petition is hashed
 * from its bytes: the generator overwrites the file in place and does not update
 * Document::contentHash, so the stored hash cannot be trusted for it.
 */
final class FilingSourceManifestBuilder
{
    public function __construct(
        private readonly FileContentHasher $hasher,
        private readonly CaseAccessoryService $accessories,
        private readonly FilingAnnexSelector $selector,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(LegalCase $case, FilingAnnexSelection $selection, OpisPlacement $placement): array
    {
        $poa = $selection->powerOfAttorney;
        $items = [];
        foreach ($case->getCountingClaimItems() as $item) {
            $items[] = [$item->getId(), number_format((float) ($item->signedAmountRon() ?? 0.0), 2, '.', '')];
        }

        return [
            'petitionSha256' => $this->bytesHash($selection->petition),
            'annexes' => array_map(fn (Document $d): array => [
                'id' => $d->getId(),
                'type' => $d->getDocumentType()->value,
                'sha256' => $d->getContentHash() ?? $this->bytesHash($d),
            ], $selection->annexes),
            'powerOfAttorney' => [
                'id' => $poa->getId(),
                'sha256' => $poa->getContentHash() ?? $this->bytesHash($poa),
                'series' => $poa->getPowerOfAttorneySeries(),
                'number' => $poa->getPowerOfAttorneyNumber(),
                'date' => $poa->getPowerOfAttorneyDate()?->format('Y-m-d'),
            ],
            'claim' => [
                'amount' => $case->getAmount(),
                'calculatedInterest' => $case->getCalculatedInterest(),
                'currency' => $case->getCurrency(),
                'items' => $items,
            ],
            'accessoryReferenceDate' => $this->accessories->referenceDate($case)->format('Y-m-d'),
            'courtId' => $case->getCourt()?->getId(),
            'opisPlacement' => $placement->value,
            'stampDuty' => [
                'status' => $case->getStampDutyStatus()->value,
                'proofAnnexed' => $selection->stampDutyProofAnnexed,
            ],
        ];
    }

    /** Cached per path, modification time and size: the petition can be several MB. */
    private function bytesHash(Document $document): ?string
    {
        $path = $this->selector->path($document);
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return null;
        }
        $key = 'filing_sha256_' . hash('xxh128', $path . '|' . filemtime($path) . '|' . filesize($path));

        return $this->cache->get($key, function (ItemInterface $item) use ($path): ?string {
            $item->expiresAfter(86400);

            return $this->hasher->hashFile($path);
        });
    }
}
