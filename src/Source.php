<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Wire;
use Finlight\Internal\WireObject;

/**
 * A source from sources->list().
 *
 * hasContent is null when the key is absent: the plan has no fullArticleAccess. That means
 * "unknown", not "no content". name and type are null until the catalog is curated.
 */
final class Source
{
    use WireObject;

    private const WIRE_KEYS = ['domain', 'name', 'type', 'isDefault', 'hasContent', 'isCustom', 'country', 'languages'];

    /**
     * @param list<string>|null    $languages
     * @param array<string, mixed> $wire
     */
    private function __construct(
        public readonly string $domain,
        public readonly ?string $name,
        public readonly ?string $type,
        public readonly ?bool $isDefault,
        public readonly ?bool $hasContent,
        public readonly ?bool $isCustom,
        public readonly ?string $country,
        public readonly ?array $languages,
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
        return new self(
            Wire::requiredString($obj, 'domain', $path),
            Wire::string($obj, 'name', $path),
            Wire::string($obj, 'type', $path),
            Wire::bool($obj, 'isDefault', $path),
            Wire::bool($obj, 'hasContent', $path),
            Wire::bool($obj, 'isCustom', $path),
            Wire::string($obj, 'country', $path),
            Wire::strings($obj, 'languages', $path),
            $obj,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rawJson(): array
    {
        return $this->wire;
    }
}
