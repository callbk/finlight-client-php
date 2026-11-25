<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\WireObject;
use Finlight\Internal\Wire;

/**
 * One entry of Article::$media (an image).
 */
final class Media
{
    use WireObject;

    private const WIRE_KEYS = ['url'];

    /**
     * @param array<string, mixed> $wire
     */
    private function __construct(public readonly ?string $url, array $wire)
    {
        $this->wire = $wire;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $obj
     */
    public static function fromWire(array $obj, string $path = 'media'): self
    {
        return new self(Wire::string($obj, 'url', $path), $obj);
    }
}
