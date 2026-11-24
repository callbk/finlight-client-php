<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Values of order (request vocabulary, lowercase).
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class SortOrder
{
    public const ASC = 'asc';
    public const DESC = 'desc';

    /** @var list<string> */
    public const ALL = [self::ASC, self::DESC];

    private function __construct()
    {
    }
}
