<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Json;
use Finlight\Internal\Time;
use Finlight\Internal\Wire;
use Finlight\Internal\WireObject;

/**
 * Company master data from companies->search(), getMany() and get().
 *
 * listings names each exchange (mic) at most once, the primary listing first. isins and listings
 * read as [] when missing. cacheInfo() is set on records from companies->get().
 */
final class CompanyRecord
{
    use WireObject;

    private const WIRE_KEYS = ['companyId', 'name', 'country', 'sector', 'industry', 'isins', 'primaryListing', 'listings', 'updatedAt'];

    /**
     * @param list<string>         $isins
     * @param list<Listing>        $listings
     * @param array<string, mixed> $wire
     */
    private function __construct(
        public readonly int $companyId,
        public readonly string $name,
        public readonly ?string $country,
        public readonly ?string $sector,
        public readonly ?string $industry,
        public readonly array $isins,
        public readonly ?Listing $primaryListing,
        public readonly array $listings,
        public readonly ?string $updatedAt,
        array $wire,
        private readonly ?CacheInfo $cacheInfo,
    ) {
        $this->wire = $wire;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $obj
     */
    public static function fromWire(array $obj, string $path = 'company', ?CacheInfo $cacheInfo = null): self
    {
        $primary = Wire::object($obj, 'primaryListing', $path);

        return new self(
            Wire::requiredInt($obj, 'companyId', $path),
            Wire::requiredString($obj, 'name', $path),
            Wire::string($obj, 'country', $path),
            Wire::string($obj, 'sector', $path),
            Wire::string($obj, 'industry', $path),
            Wire::strings($obj, 'isins', $path) ?? [],
            $primary === null ? null : Listing::fromWire($primary, $path . '.primaryListing'),
            Wire::objects($obj, 'listings', $path, static fn (array $o, string $p): Listing => Listing::fromWire($o, $p)) ?? [],
            Wire::string($obj, 'updatedAt', $path),
            $obj,
            $cacheInfo,
        );
    }

    /**
     * @internal
     */
    public static function fromJson(mixed $value, string $path = 'company', ?CacheInfo $cacheInfo = null): self
    {
        if (!Json::isObject($value)) {
            throw Wire::fail($path, $value, 'is not a JSON object');
        }

        return self::fromWire($value, $path, $cacheInfo);
    }

    /**
     * @internal
     */
    public function withCacheInfo(CacheInfo $cacheInfo): self
    {
        return self::fromWire($this->wire, 'company', $cacheInfo);
    }

    public function updatedAtTime(): ?\DateTimeImmutable
    {
        return Time::parse($this->updatedAt);
    }

    /** ETag and Cache-Control of the answer this record came from (companies->get() only). */
    public function cacheInfo(): ?CacheInfo
    {
        return $this->cacheInfo;
    }

    /**
     * @return array<string, mixed>
     */
    public function rawJson(): array
    {
        return $this->wire;
    }
}
