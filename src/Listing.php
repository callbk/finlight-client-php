<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\WireObject;
use Finlight\Internal\Wire;

/**
 * A listing: primaryListing of a company, or one entry of CompanyRecord::$listings.
 * active is only sent inside CompanyRecord::$listings.
 */
final class Listing
{
    use WireObject;

    private const WIRE_KEYS = ['ticker', 'mic', 'country', 'active'];

    /**
     * @param array<string, mixed> $wire
     */
    private function __construct(
        public readonly ?string $ticker,
        public readonly ?string $mic,
        public readonly ?string $country,
        public readonly ?bool $active,
        array $wire,
    ) {
        $this->wire = $wire;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $obj
     */
    public static function fromWire(array $obj, string $path = 'listing'): self
    {
        return new self(
            Wire::string($obj, 'ticker', $path),
            Wire::string($obj, 'mic', $path),
            Wire::string($obj, 'country', $path),
            Wire::bool($obj, 'active', $path),
            $obj,
        );
    }
}
