<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\WireObject;
use Finlight\Internal\Wire;

/**
 * A company tagged on an article. role (open, CompanyRole) and relevance are null on articles
 * tagged before roles existed. primaryListing is null for private companies.
 */
final class Company
{
    use WireObject;

    private const WIRE_KEYS = ['companyId', 'name', 'isin', 'primaryListing', 'confidence', 'role', 'relevance'];

    /**
     * @param array<string, mixed> $wire
     */
    private function __construct(
        public readonly int $companyId,
        public readonly string $name,
        public readonly ?string $isin,
        public readonly ?Listing $primaryListing,
        public readonly ?float $confidence,
        public readonly ?string $role,
        public readonly ?float $relevance,
        array $wire,
    ) {
        $this->wire = $wire;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $obj
     */
    public static function fromWire(array $obj, string $path = 'company'): self
    {
        $listing = Wire::object($obj, 'primaryListing', $path);

        return new self(
            Wire::requiredInt($obj, 'companyId', $path),
            Wire::requiredString($obj, 'name', $path),
            Wire::string($obj, 'isin', $path),
            $listing === null ? null : Listing::fromWire($listing, $path . '.primaryListing'),
            Wire::number($obj, 'confidence', $path),
            Wire::string($obj, 'role', $path),
            Wire::number($obj, 'relevance', $path),
            $obj,
        );
    }
}
