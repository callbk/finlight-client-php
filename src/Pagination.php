<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Wire;
use Finlight\Internal\WireObject;

/**
 * Pagination of a search page. The search is over when hasMore is false or nextCursor is null.
 * There is no total count.
 */
final class Pagination
{
    use WireObject;

    private const WIRE_KEYS = ['limit', 'returned', 'hasMore', 'nextCursor'];

    /**
     * @param array<string, mixed> $wire
     */
    private function __construct(
        public readonly ?int $limit,
        public readonly ?int $returned,
        public readonly ?bool $hasMore,
        public readonly ?string $nextCursor,
        array $wire,
    ) {
        $this->wire = $wire;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $obj
     */
    public static function fromWire(array $obj, string $path = 'pagination'): self
    {
        return new self(
            Wire::int($obj, 'limit', $path),
            Wire::int($obj, 'returned', $path),
            Wire::bool($obj, 'hasMore', $path),
            Wire::string($obj, 'nextCursor', $path),
            $obj,
        );
    }
}
