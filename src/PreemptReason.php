<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known reasons of connection.preempted. Open.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class PreemptReason
{
    public const CONNECTION_LIMIT = 'connection_limit';
    public const QUOTA_EXCEEDED = 'quota_exceeded';

    /** @var list<string> */
    public const ALL = [self::CONNECTION_LIMIT, self::QUOTA_EXCEEDED];

    private function __construct()
    {
    }
}
