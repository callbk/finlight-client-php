<?php

declare(strict_types=1);

namespace Finlight\Model;

use Finlight\Internal\Value;

final class Article
{
    /**
     * @param list<string>|null  $images
     * @param list<Company>|null $companies
     * @param list<string>|null  $categories
     * @param list<string>|null  $countries  ISO 3166-1 alpha-2
     */
    public function __construct(
        public readonly string $link,
        public readonly string $title,
        public readonly \DateTimeImmutable $publishDate,
        public readonly string $source,
        public readonly string $language,
        public readonly ?string $sentiment = null,
        public readonly ?float $confidence = null,
        public readonly ?string $summary = null,
        public readonly ?array $images = null,
        public readonly ?string $content = null,
        public readonly ?array $companies = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $revisedDate = null,
        public readonly ?bool $isUpdate = null,
        public readonly ?array $categories = null,
        public readonly ?array $countries = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $companies = Value::nullableObjectList($data, 'companies');

        return new self(
            link: Value::string($data, 'link', 'Article'),
            title: Value::string($data, 'title', 'Article'),
            publishDate: Value::dateTime($data, 'publishDate', 'Article'),
            source: Value::string($data, 'source', 'Article'),
            language: Value::string($data, 'language', 'Article'),
            sentiment: Value::nullableString($data, 'sentiment'),
            confidence: Value::nullableFloat($data, 'confidence'),
            summary: Value::nullableString($data, 'summary'),
            images: Value::nullableStringList($data, 'images'),
            content: Value::nullableString($data, 'content'),
            companies: $companies === null
                ? null
                : array_map(static fn (array $company): Company => Company::fromArray($company), $companies),
            createdAt: Value::nullableDateTime($data, 'createdAt'),
            revisedDate: Value::nullableDateTime($data, 'revisedDate'),
            isUpdate: Value::nullableBool($data, 'isUpdate'),
            categories: Value::nullableStringList($data, 'categories'),
            countries: Value::nullableStringList($data, 'countries'),
        );
    }
}
