<?php

declare(strict_types=1);

namespace Finlight;

/**
 * HTTP cache data of a record from companies->get(): the ETag (pass it as ifNoneMatch later) and
 * the Cache-Control header. The SDK keeps no cache; use these to keep the record yourself.
 */
final class CacheInfo
{
    public function __construct(
        public readonly ?string $etag,
        public readonly ?string $cacheControl,
    ) {
    }
}
