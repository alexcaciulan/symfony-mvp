<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Enum\FilingPackageStatus;
use App\Enum\SignatureStatus;

/**
 * Turns the parts of a filing package into the rows of the signing card: the
 * state of each row, every observation in full (match notes first, then the
 * signature warnings), the last refused upload while uploads are still open, and
 * whether the whole card can fold to its summary. No observation is dropped: the
 * short label only ever summarises the full text shown next to it.
 */
final class FilingPartRowPresenter
{
    private const STRONG_NOTES = ['filing.signature.note.text_added_with_digits'];

    private const OVER_BUDGET_KEYS = ['filing.upload.over_budget.text', 'filing.upload.error.first_email_too_large'];

    public function present(FilingPackage $package, bool $uploadsOpen): FilingPartRows
    {
        $rows = [];
        $anySignature = false;
        $anyRefusal = false;
        foreach ($package->getParts() as $part) {
            $row = $this->row($part, $uploadsOpen);
            $rows[] = $row;
            $anySignature = $anySignature || $part->hasSignedFile();
            $anyRefusal = $anyRefusal || $row->refusal !== null;
        }

        return new FilingPartRows(
            $rows,
            $uploadsOpen,
            $anySignature,
            $package->getStatus() === FilingPackageStatus::SIGNED && !$anyRefusal,
        );
    }

    public function row(FilingPackagePart $part, bool $uploadsOpen): FilingPartRow
    {
        $refusal = $uploadsOpen ? $this->refusal($part) : null;
        $base = $this->signatureState($part);
        $state = match (true) {
            $refusal === null, $base === FilingPartRow::STATE_AWAITING => $base,
            $base === FilingPartRow::STATE_MISSING => FilingPartRow::STATE_REFUSED,
            default => FilingPartRow::STATE_REFUSED_KEPT_PREVIOUS,
        };

        return new FilingPartRow(
            $part,
            $state,
            $part->hasSignedFile() ? $this->observations($part) : [],
            $refusal,
            $base === FilingPartRow::STATE_AWAITING,
        );
    }

    private function signatureState(FilingPackagePart $part): string
    {
        return match (true) {
            $part->isAwaitingProbableConfirmation() => FilingPartRow::STATE_AWAITING,
            $part->getSignatureStatus() === SignatureStatus::PRESENT_PROBABLE && $part->isSignatureAccepted() => FilingPartRow::STATE_CONFIRMED,
            $part->getSignatureStatus() === SignatureStatus::PRESENT_MATCHED => FilingPartRow::STATE_MATCHED,
            default => FilingPartRow::STATE_MISSING,
        };
    }

    /**
     * @return list<array{key: string, params: array<string, string|int>, shortKey: string, strong: bool, kind: string, slug: string}>
     */
    private function observations(FilingPackagePart $part): array
    {
        $observations = [];
        foreach ($part->getSignatureMatchNotes() as $note) {
            $observations[] = $this->observation((string) $note['key'], $note['params'] ?? [], 'note');
        }
        foreach ($part->getSignatureWarnings() as $warning) {
            // Warnings are stored as plain keys; a structured one is accepted all the same.
            if (is_array($warning)) {
                $observations[] = $this->observation((string) ($warning['key'] ?? ''), $warning['params'] ?? [], 'warning');
            } else {
                $observations[] = $this->observation((string) $warning, [], 'warning');
            }
        }

        return $observations;
    }

    /**
     * @param array<string, string|int> $params
     *
     * @return array{key: string, params: array<string, string|int>, shortKey: string, strong: bool, kind: string, slug: string}
     */
    private function observation(string $key, array $params, string $kind): array
    {
        $slug = self::slug($key);

        return [
            'key' => $key,
            'params' => $params,
            'shortKey' => 'filing.signature.short.' . $slug,
            'strong' => in_array($key, self::STRONG_NOTES, true),
            'kind' => $kind,
            'slug' => $slug,
        ];
    }

    /**
     * @return array{key: string, slug: string, params: array<string, string>, at: \DateTimeImmutable, keptPrevious: bool, overBudget: bool, rebuildable: bool, excessBytes: int}|null
     */
    private function refusal(FilingPackagePart $part): ?array
    {
        $key = $part->getLastRefusalKey();
        $at = $part->getLastRefusalAt();
        if ($key === null || $at === null) {
            return null;
        }

        $stored = $part->getLastRefusalParams();
        $excess = (int) ($stored['excessBytes'] ?? 0);
        $overBudget = in_array($key, self::OVER_BUDGET_KEYS, true);

        return [
            'key' => $key,
            'slug' => $overBudget ? ($key === 'filing.upload.over_budget.text' ? 'over_budget' : 'first_email_too_large') : self::slug($key),
            'params' => $overBudget ? ['%excess%' => number_format($excess / 1048576, 2, ',', '.')] : [],
            'at' => $at,
            'keptPrevious' => $part->hasSignedFile(),
            'overBudget' => $overBudget,
            'rebuildable' => $overBudget && (bool) ($stored['rebuildable'] ?? false),
            'excessBytes' => $excess,
        ];
    }

    private static function slug(string $key): string
    {
        $dot = strrpos($key, '.');

        return $dot === false ? $key : substr($key, $dot + 1);
    }
}
