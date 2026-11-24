<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known values of source.type and of the sourceTypes / excludeSourceTypes search filters.
 * Open: unknown values are kept as sent.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class SourceType
{
    public const NEWSWIRE = 'newswire';
    public const PRESS_RELEASE = 'press_release';
    public const FINANCIAL_MEDIA = 'financial_media';
    public const GENERAL_MEDIA = 'general_media';
    public const AGGREGATOR = 'aggregator';
    public const BLOG = 'blog';
    public const GOVERNMENT = 'government';

    /** @var list<string> */
    public const ALL = [
        self::NEWSWIRE,
        self::PRESS_RELEASE,
        self::FINANCIAL_MEDIA,
        self::GENERAL_MEDIA,
        self::AGGREGATOR,
        self::BLOG,
        self::GOVERNMENT,
    ];

    private function __construct()
    {
    }
}
