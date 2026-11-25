<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Time;
use Finlight\Internal\Wire;
use Finlight\Internal\WireObject;

/**
 * A verified v3 webhook delivery. Deduplicate on eventId: deliveries can repeat.
 *
 * event is open (WebhookEventName). changedFields is null when the key was not sent (only
 * article.updated carries it). isTest() is true for the dashboard test webhook.
 */
final class WebhookEvent
{
    use WireObject;

    private const WIRE_KEYS = ['eventId', 'event', 'occurredAt', 'payloadVersion', 'subscriptionId', 'changedFields', 'data'];

    /**
     * @param list<string>|null    $changedFields
     * @param array<string, mixed> $wire
     */
    private function __construct(
        public readonly string $eventId,
        public readonly string $event,
        public readonly ?string $occurredAt,
        public readonly int|float $payloadVersion,
        public readonly ?string $subscriptionId,
        public readonly ?array $changedFields,
        public readonly Article $data,
        array $wire,
    ) {
        $this->wire = $wire;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $obj
     */
    public static function fromWire(array $obj): self
    {
        $path = 'webhook';
        $version = $obj['payloadVersion'] ?? null;
        if (!is_int($version) && !is_float($version)) {
            throw Wire::fail($path . '.payloadVersion', $version, 'is not a number');
        }
        $data = Wire::object($obj, 'data', $path);
        if ($data === null) {
            throw Wire::fail($path . '.data', null, 'is missing or null');
        }

        return new self(
            Wire::requiredString($obj, 'eventId', $path),
            Wire::requiredString($obj, 'event', $path),
            Wire::string($obj, 'occurredAt', $path),
            $version,
            Wire::string($obj, 'subscriptionId', $path),
            Wire::strings($obj, 'changedFields', $path),
            Article::fromWire($data, $path . '.data'),
            $obj,
        );
    }

    /** True for the dashboard test webhook (eventId starts with evt_test). */
    public function isTest(): bool
    {
        return str_starts_with($this->eventId, 'evt_test');
    }

    public function occurredAtTime(): ?\DateTimeImmutable
    {
        return Time::parse($this->occurredAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function rawJson(): array
    {
        return $this->wire;
    }
}
