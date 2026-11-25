<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Kind of a 429 (RateLimitedException::limitType()). Open: a newer value the server sends is kept
 * as a string and never retried. UNKNOWN means the answer did not say.
 *
 * Values are plain strings. Compare with ===. ALL lists the values the API documents today.
 */
final class RateLimitType
{
    public const MONTHLY = 'monthly';
    public const BURST = 'burst';
    public const UNKNOWN = 'unknown';

    /** @var list<string> */
    public const ALL = [self::MONTHLY, self::BURST];

    private function __construct()
    {
    }
}
