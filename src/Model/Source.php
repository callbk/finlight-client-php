<?php

declare(strict_types=1);

namespace Finlight\Model;

use Finlight\Internal\Value;

final class Source
{
    /**
     * @param list<string>|null $languages ISO 639-1, primary first
     */
    public function __construct(
        public readonly string $domain,
        public readonly bool $isDefaultSource,
        public readonly ?bool $isContentAvailable = null,
        public readonly ?string $originCountry = null,
        public readonly ?array $languages = null,
        public readonly ?bool $isCustomSource = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            domain: Value::string($data, 'domain', 'Source'),
            isDefaultSource: Value::nullableBool($data, 'isDefaultSource') ?? false,
            isContentAvailable: Value::nullableBool($data, 'isContentAvailable'),
            originCountry: Value::nullableString($data, 'originCountry'),
            languages: Value::nullableStringList($data, 'languages'),
            isCustomSource: Value::nullableBool($data, 'isCustomSource'),
        );
    }
}
