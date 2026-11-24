<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\WireObject;
use Finlight\Internal\Wire;

/**
 * Article::$sentiment. label is open (SentimentLabel) and can be null; confidence is not rounded.
 */
final class Sentiment
{
    use WireObject;

    private const WIRE_KEYS = ['label', 'confidence'];

    /**
     * @param array<string, mixed> $wire
     */
    private function __construct(
        public readonly ?string $label,
        public readonly ?float $confidence,
        array $wire,
    ) {
        $this->wire = $wire;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $obj
     */
    public static function fromWire(array $obj, string $path = 'sentiment'): self
    {
        return new self(Wire::string($obj, 'label', $path), Wire::number($obj, 'confidence', $path), $obj);
    }
}
