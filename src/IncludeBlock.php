<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Blocks of include: content, companies, events (request vocabulary).
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class IncludeBlock
{
    public const CONTENT = 'content';
    public const COMPANIES = 'companies';
    public const EVENTS = 'events';

    /** @var list<string> */
    public const ALL = [self::CONTENT, self::COMPANIES, self::EVENTS];

    private function __construct()
    {
    }
}
