<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Values of orderBy (request vocabulary). The sort timestamp is also the field from/to apply to.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class OrderBy
{
    public const PUBLISHED_AT = 'publishedAt';
    public const FIRST_SEEN_AT = 'firstSeenAt';
    public const REVISED_AT = 'revisedAt';
    public const AVAILABLE_AT = 'availableAt';

    /** @var list<string> */
    public const ALL = [self::PUBLISHED_AT, self::FIRST_SEEN_AT, self::REVISED_AT, self::AVAILABLE_AT];

    private function __construct()
    {
    }
}
