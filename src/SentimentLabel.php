<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known values of sentiment.label. Open: unknown values are kept as sent.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class SentimentLabel
{
    public const POSITIVE = 'positive';
    public const NEGATIVE = 'negative';
    public const NEUTRAL = 'neutral';

    /** @var list<string> */
    public const ALL = [self::POSITIVE, self::NEGATIVE, self::NEUTRAL];

    private function __construct()
    {
    }
}
