<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\WireObject;
use Finlight\Internal\Wire;

/**
 * Article::$source. domain is the join key to sources->list(). type is the source type when the
 * article was indexed (open, SourceType); null when the source is not classified.
 */
final class ArticleSource
{
    use WireObject;

    private const WIRE_KEYS = ['domain', 'type'];

    /**
     * @param array<string, mixed> $wire
     */
    private function __construct(
        public readonly string $domain,
        public readonly ?string $type,
        array $wire,
    ) {
        $this->wire = $wire;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $obj
     */
    public static function fromWire(array $obj, string $path = 'source'): self
    {
        return new self(Wire::requiredString($obj, 'domain', $path), Wire::string($obj, 'type', $path), $obj);
    }
}
