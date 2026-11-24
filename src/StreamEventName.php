<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known WebSocket event names. Open: unknown events decode as Frame::OTHER.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class StreamEventName
{
    public const ARTICLE_CREATED = 'article.created';
    public const ARTICLE_UPDATED = 'article.updated';
    public const CONNECTION_ADMITTED = 'connection.admitted';
    public const CONNECTION_PONG = 'connection.pong';
    public const CONNECTION_PREEMPTED = 'connection.preempted';
    public const CONNECTION_ERROR = 'connection.error';

    /** @var list<string> */
    public const ALL = [self::ARTICLE_CREATED, self::ARTICLE_UPDATED, self::CONNECTION_ADMITTED, self::CONNECTION_PONG, self::CONNECTION_PREEMPTED, self::CONNECTION_ERROR];

    /** @var list<string> */
    public const ARTICLE_EVENTS = [self::ARTICLE_CREATED, self::ARTICLE_UPDATED];

    /** @var list<string> */
    public const CONNECTION_EVENTS = [self::CONNECTION_ADMITTED, self::CONNECTION_PONG, self::CONNECTION_PREEMPTED, self::CONNECTION_ERROR];

    private function __construct()
    {
    }
}
