<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\WireObject;
use Finlight\Internal\Wire;

/**
 * An event classified on an article. type, direction and importance are open (EventType,
 * EventDirection, Importance).
 *
 * companyIds is null when the key is absent: it is only sent when the companies block is in the
 * same payload. It is never turned into [].
 */
final class EventTag
{
    use WireObject;

    private const WIRE_KEYS = ['type', 'direction', 'importance', 'confidence', 'companyIds'];

    /**
     * @param list<int>|null       $companyIds
     * @param array<string, mixed> $wire
     */
    private function __construct(
        public readonly string $type,
        public readonly ?string $direction,
        public readonly ?string $importance,
        public readonly ?float $confidence,
        public readonly ?array $companyIds,
        array $wire,
    ) {
        $this->wire = $wire;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $obj
     */
    public static function fromWire(array $obj, string $path = 'event'): self
    {
        return new self(
            Wire::requiredString($obj, 'type', $path),
            Wire::string($obj, 'direction', $path),
            Wire::string($obj, 'importance', $path),
            Wire::number($obj, 'confidence', $path),
            Wire::ints($obj, 'companyIds', $path),
            $obj,
        );
    }
}
