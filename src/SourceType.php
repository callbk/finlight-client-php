<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known values of source.type (none yet: the server sends null today). Open.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class SourceType
{
    /** @var list<string> */
    public const ALL = [];

    private function __construct()
    {
    }
}
