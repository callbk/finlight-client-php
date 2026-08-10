<?php

declare(strict_types=1);

namespace Finlight\Model;

use Finlight\Internal\Value;

final class Listing
{
    public function __construct(
        public readonly string $ticker,
        public readonly string $exchangeCode,
        public readonly string $exchangeCountry,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ticker: Value::string($data, 'ticker', 'Listing'),
            exchangeCode: Value::string($data, 'exchangeCode', 'Listing'),
            exchangeCountry: Value::string($data, 'exchangeCountry', 'Listing'),
        );
    }
}
