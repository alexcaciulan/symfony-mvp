<?php

namespace App\Service\Portal;

use App\Entity\LegalCase;
use App\Enum\PortalEventType;
use App\Repository\CourtPortalEventRepository;

class PortalEventDetector
{
    public function __construct(
        private CourtPortalEventRepository $eventRepository,
    ) {}

    /**
     * Compare portal data with stored events and return new events to create.
     *
     * @return array<int, array{
     *     type: PortalEventType,
     *     eventDate: ?\DateTimeInterface,
     *     description: string,
     *     solutie: ?string,
     *     solutieSumar: ?string,
     *     rawData: array
     * }>
     */
    public function detectNewEvents(LegalCase $case, array $dosarData): array
    {
        $newEvents = [];

        foreach ($dosarData['sedinte'] ?? [] as $sedinta) {
            $hearingDate = $this->parseDate($sedinta['data'] ?? null);

            if (!empty($sedinta['solutie']) || !empty($sedinta['solutieSumar'])) {
                // Hearing with a ruling = completed
                $pronounceDate = $this->parseDate($sedinta['dataPronuntare'] ?? null);
                $checkDate = $pronounceDate ?? $hearingDate;

                if (!$this->eventRepository->eventExists($case, PortalEventType::HEARING_COMPLETED, $checkDate)) {
                    $newEvents[] = [
                        'type' => PortalEventType::HEARING_COMPLETED,
                        'eventDate' => $checkDate,
                        'description' => $this->buildHearingCompletedDescription($sedinta),
                        'solutie' => $sedinta['solutie'] ?? null,
                        'solutieSumar' => $sedinta['solutieSumar'] ?? null,
                        'rawData' => $sedinta,
                    ];
                }
            } elseif ($hearingDate !== null) {
                // Future hearing without ruling = scheduled
                if (!$this->eventRepository->eventExists($case, PortalEventType::HEARING_SCHEDULED, $hearingDate)) {
                    $newEvents[] = [
                        'type' => PortalEventType::HEARING_SCHEDULED,
                        'eventDate' => $hearingDate,
                        'description' => sprintf(
                            'Termen de judecată fixat: %s',
                            $hearingDate->format('d.m.Y'),
                        ),
                        'solutie' => null,
                        'solutieSumar' => null,
                        'rawData' => $sedinta,
                    ];
                }
            }
        }

        foreach ($dosarData['caiAtac'] ?? [] as $caleAtac) {
            $appealDate = $this->parseDate($caleAtac['dataDeclarare'] ?? null);

            if (!$this->eventRepository->eventExists($case, PortalEventType::APPEAL_FILED, $appealDate)) {
                $newEvents[] = [
                    'type' => PortalEventType::APPEAL_FILED,
                    'eventDate' => $appealDate,
                    // "Cale de atac" (feminine) is the grammatical head, so
                    // "declarată" agrees regardless of the appeal type (Apel,
                    // Recurs, Cerere în anulare); the type stays parenthetical.
                    'description' => sprintf(
                        'Cale de atac (%s) declarată de %s',
                        $caleAtac['tipCaleAtac'] ?? 'tip necunoscut',
                        $caleAtac['parteDeclaratoare'] ?? 'parte necunoscută',
                    ),
                    'solutie' => null,
                    'solutieSumar' => null,
                    'rawData' => $caleAtac,
                ];
            }
        }

        // Check for procedural stage change
        $stadiuProcesual = $dosarData['stadiuProcesual'] ?? null;
        if ($stadiuProcesual !== null) {
            if (!$this->eventRepository->eventExists($case, PortalEventType::CASE_INFO_UPDATE, null)) {
                $newEvents[] = [
                    'type' => PortalEventType::CASE_INFO_UPDATE,
                    'eventDate' => null,
                    'description' => sprintf('Stadiu procesual: %s', $stadiuProcesual),
                    'solutie' => null,
                    'solutieSumar' => null,
                    'rawData' => ['stadiuProcesual' => $stadiuProcesual],
                ];
            }
        }

        return $newEvents;
    }

    private function parseDate(?string $dateStr): ?\DateTimeInterface
    {
        if ($dateStr === null || $dateStr === '') {
            return null;
        }

        // Portal returns dates in various formats; return mutable DateTime for Doctrine DATE_MUTABLE
        foreach (['d.m.Y', 'Y-m-d', 'd/m/Y', 'Y-m-d\TH:i:s'] as $format) {
            $date = \DateTime::createFromFormat($format, $dateStr);
            if ($date !== false) {
                $date->setTime(0, 0);

                return $date;
            }
        }

        return null;
    }

    private function buildHearingCompletedDescription(array $sedinta): string
    {
        $parts = ['Ședință finalizată'];

        $date = $sedinta['data'] ?? null;
        if ($date !== null) {
            $parsed = $this->parseDate($date);
            if ($parsed !== null) {
                $parts[0] .= ' din ' . $parsed->format('d.m.Y');
            }
        }

        // Use the short ruling type (solutie) for the headline; the full text
        // lives in the dedicated solutieSumar field (shown separately in the
        // timeline and email). Fall back to solutieSumar only if the type is
        // missing, so the description never loses the ruling entirely.
        $ruling = $sedinta['solutie'] ?? null;
        if ($ruling === null || $ruling === '') {
            $ruling = $sedinta['solutieSumar'] ?? null;
        }
        if ($ruling !== null && $ruling !== '') {
            $parts[] = $ruling;
        }

        return implode('. ', $parts);
    }
}
