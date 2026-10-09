<?php

declare(strict_types=1);

namespace App\Service\Portal\Dto;

/**
 * A scored portal.just.ro case candidate presented to the lawyer for confirmation.
 * The lawyer picks one suggestion to activate monitoring; we never auto-activate,
 * since matching is name-based only (portal exposes no CUI/CNP).
 *
 * @phpstan-type PortalParty array{nume: ?string, calitateParte: ?string}
 */
final readonly class PortalCaseSuggestion
{
    /**
     * @param array<int, array{nume: ?string, calitateParte: ?string}> $parti
     * @param array<int, string>                                        $matchedPartyNames Our party names that matched a portal party
     * @param bool                                                      $wasDismissed      The lawyer said it is not this case: listed last, never proposed
     * @param bool                                                      $registeredBeforeRequest Registered before our request was generated: listed last, never proposed
     */
    public function __construct(
        public string $numar,
        public ?string $institutie,
        public ?string $obiect,
        public ?string $dataInregistrare,
        public ?string $stadiuProcesual,
        public ?string $dataModificare,
        public array $parti,
        public int $score,
        public array $matchedPartyNames,
        public bool $isHighConfidence,
        public bool $wasDismissed = false,
        public bool $registeredBeforeRequest = false,
    ) {}
}
