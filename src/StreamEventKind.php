<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Kind of a StreamEvent: created, updated, or other (an event this SDK does not know).
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class StreamEventKind
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const OTHER = 'other';

    /** @var list<string> */
    public const ALL = [self::CREATED, self::UPDATED, self::OTHER];

    private function __construct()
    {
    }
}
