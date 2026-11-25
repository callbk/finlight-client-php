<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known values of events[].importance and minImportance, in order from low to critical. Open.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class Importance
{
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';
    public const CRITICAL = 'critical';

    /** @var list<string> */
    public const ALL = [self::LOW, self::MEDIUM, self::HIGH, self::CRITICAL];

    private function __construct()
    {
    }
}
