<?php

declare(strict_types=1);

namespace Finlight\Model;

use Finlight\Internal\Value;

final class Company
{
    /**
     * @param list<string>|null  $isins
     * @param list<Listing>|null $otherListings
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $name,
        public readonly string $ticker,
        public readonly ?float $confidence = null,
        public readonly ?string $country = null,
        public readonly ?string $exchange = null,
        public readonly ?string $industry = null,
        public readonly ?string $sector = null,
        public readonly ?string $isin = null,
        public readonly ?string $openfigi = null,
        public readonly ?Listing $primaryListing = null,
        public readonly ?array $isins = null,
        public readonly ?array $otherListings = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $primaryListing = Value::nullableObject($data, 'primaryListing');
        $otherListings = Value::nullableObjectList($data, 'otherListings');

        return new self(
            companyId: Value::int($data, 'companyId', 'Company'),
            name: Value::string($data, 'name', 'Company'),
            ticker: Value::string($data, 'ticker', 'Company'),
            confidence: Value::nullableFloat($data, 'confidence'),
            country: Value::nullableString($data, 'country'),
            exchange: Value::nullableString($data, 'exchange'),
            industry: Value::nullableString($data, 'industry'),
            sector: Value::nullableString($data, 'sector'),
            isin: Value::nullableString($data, 'isin'),
            openfigi: Value::nullableString($data, 'openfigi'),
            primaryListing: $primaryListing === null ? null : Listing::fromArray($primaryListing),
            isins: Value::nullableStringList($data, 'isins'),
            otherListings: $otherListings === null
                ? null
                : array_map(static fn (array $listing): Listing => Listing::fromArray($listing), $otherListings),
        );
    }
}
