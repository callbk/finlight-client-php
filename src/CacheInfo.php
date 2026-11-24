<?php

declare(strict_types=1);

namespace Finlight;

/**
 * HTTP cache data of a record from companies->get(): the ETag (pass it as ifNoneMatch later),
 * the Cache-Control header, and whether the record came from a 304 revalidation.
 */
final class CacheInfo
{
    public function __construct(
        public readonly ?string $etag,
        public readonly ?string $cacheControl,
        public readonly bool $notModified,
    ) {
    }
}
