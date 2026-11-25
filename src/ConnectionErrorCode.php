<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known codes of a connection.error frame. Open.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class ConnectionErrorCode
{
    public const PING_ON_DEFAULT_ROUTE = 'PING_ON_DEFAULT_ROUTE';
    public const HANDSHAKE_FAILED = 'HANDSHAKE_FAILED';
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const SEARCH_FAILED = 'SEARCH_FAILED';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    /** @var list<string> */
    public const ALL = [self::PING_ON_DEFAULT_ROUTE, self::HANDSHAKE_FAILED, self::VALIDATION_FAILED, self::SEARCH_FAILED, self::INTERNAL_ERROR];

    private function __construct()
    {
    }
}
