<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * In-memory Mercure hub used in the test environment: records published updates
 * instead of POSTing them to the real hub, so the suite never performs network
 * egress to Mercure. Aliased over {@see HubInterface} in
 * config/packages/test/services.yaml, so every service that publishes (e.g.
 * ExtractionMercurePublisher) hits this recorder. Tests may fetch it from the
 * container to assert the published topics/payloads.
 */
final class RecordingHub implements HubInterface
{
    /** @var Update[] */
    public array $updates = [];

    public function getPublicUrl(): string
    {
        return 'http://localhost/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        $this->updates[] = $update;

        return 'urn:uuid:recording-hub-' . \count($this->updates);
    }
}
