<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known webhook event names. Open: unknown names are kept as sent.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class WebhookEventName
{
    public const ARTICLE_CREATED = 'article.created';
    public const ARTICLE_UPDATED = 'article.updated';

    /** @var list<string> */
    public const ALL = [self::ARTICLE_CREATED, self::ARTICLE_UPDATED];

    private function __construct()
    {
    }
}
