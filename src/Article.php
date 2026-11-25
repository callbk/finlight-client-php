<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Json;
use Finlight\Internal\Time;
use Finlight\Internal\Wire;
use Finlight\Internal\WireObject;

/**
 * An article of the finlight API v3. The same shape in REST, WebSocket and webhooks.
 *
 * Absent, null and empty:
 * - content and sentiment have three states. contentPresent() / sentimentPresent() say whether the
 *   key was sent at all (not requested, not entitled, or a raw channel when false). With the key
 *   present, null means "no value".
 * - companies and events are null when the key was not sent, [] when the article was tagged and
 *   nothing was found. companiesPresent() / eventsPresent() say the same.
 * - media, countries and categories read as [] when missing or null.
 *
 * Timestamps keep the string that was sent (publishedAt, ...). publishedAtTime() and the other
 * *Time() methods parse them strictly (RFC 3339) and return null for anything unreadable.
 * rawJson() gives the JSON object as received, including keys newer than this SDK.
 */
final class Article
{
    use WireObject;

    private const WIRE_KEYS = [
        'id', 'link', 'source', 'title', 'summary', 'language', 'media', 'publishedAt', 'firstSeenAt',
        'revisedAt', 'availableAt', 'countries', 'categories', 'content', 'sentiment', 'companies', 'events',
    ];

    /**
     * @param list<Media>          $media
     * @param list<string>         $countries
     * @param list<string>         $categories
     * @param list<Company>|null   $companies
     * @param list<EventTag>|null  $events
     * @param array<string, mixed> $wire
     */
    private function __construct(
        public readonly string $id,
        public readonly string $link,
        public readonly ArticleSource $source,
        public readonly string $title,
        public readonly ?string $summary,
        public readonly ?string $language,
        public readonly array $media,
        public readonly ?string $publishedAt,
        public readonly ?string $firstSeenAt,
        public readonly ?string $revisedAt,
        public readonly ?string $availableAt,
        public readonly array $countries,
        public readonly array $categories,
        public readonly ?string $content,
        public readonly ?Sentiment $sentiment,
        public readonly ?array $companies,
        public readonly ?array $events,
        array $wire,
    ) {
        $this->wire = $wire;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $obj
     *
     * @throws FinlightDecodeException when a required key is missing or a value has the wrong type
     */
    public static function fromWire(array $obj, string $path = 'article'): self
    {
        $source = Wire::object($obj, 'source', $path);
        if ($source === null) {
            throw Wire::fail($path . '.source', null, 'is missing or null');
        }
        $sentiment = Wire::object($obj, 'sentiment', $path);

        return new self(
            Wire::requiredString($obj, 'id', $path),
            Wire::requiredString($obj, 'link', $path),
            ArticleSource::fromWire($source, $path . '.source'),
            Wire::requiredString($obj, 'title', $path),
            Wire::string($obj, 'summary', $path),
            Wire::string($obj, 'language', $path),
            Wire::objects($obj, 'media', $path, static fn (array $o, string $p): Media => Media::fromWire($o, $p)) ?? [],
            Wire::string($obj, 'publishedAt', $path),
            Wire::string($obj, 'firstSeenAt', $path),
            Wire::string($obj, 'revisedAt', $path),
            Wire::string($obj, 'availableAt', $path),
            Wire::strings($obj, 'countries', $path) ?? [],
            Wire::strings($obj, 'categories', $path) ?? [],
            Wire::string($obj, 'content', $path),
            $sentiment === null ? null : Sentiment::fromWire($sentiment, $path . '.sentiment'),
            Wire::objects($obj, 'companies', $path, static fn (array $o, string $p): Company => Company::fromWire($o, $p)),
            Wire::objects($obj, 'events', $path, static fn (array $o, string $p): EventTag => EventTag::fromWire($o, $p)),
            $obj,
        );
    }

    /**
     * @internal
     */
    public static function fromJson(mixed $value, string $path = 'article'): self
    {
        if (!Json::isObject($value)) {
            throw Wire::fail($path, $value, 'is not a JSON object');
        }

        return self::fromWire($value, $path);
    }

    /** True when the content key was sent, even as null. */
    public function contentPresent(): bool
    {
        return array_key_exists('content', $this->wire);
    }

    /** True when the sentiment key was sent, even as null. */
    public function sentimentPresent(): bool
    {
        return array_key_exists('sentiment', $this->wire);
    }

    /** True when the companies key was sent. [] then means: tagged, no company. */
    public function companiesPresent(): bool
    {
        return $this->companies !== null;
    }

    /** True when the events key was sent. [] then means: classified, no event. */
    public function eventsPresent(): bool
    {
        return $this->events !== null;
    }

    public function publishedAtTime(): ?\DateTimeImmutable
    {
        return Time::parse($this->publishedAt);
    }

    public function firstSeenAtTime(): ?\DateTimeImmutable
    {
        return Time::parse($this->firstSeenAt);
    }

    public function revisedAtTime(): ?\DateTimeImmutable
    {
        return Time::parse($this->revisedAt);
    }

    public function availableAtTime(): ?\DateTimeImmutable
    {
        return Time::parse($this->availableAt);
    }

    /**
     * The JSON object as received (standard PHP arrays), unknown keys included. Read only.
     *
     * @return array<string, mixed>
     */
    public function rawJson(): array
    {
        return $this->wire;
    }
}
