<?php

declare(strict_types=1);

namespace Finlight;

/**
 * One page of articles->search(). Entries that could not be read are in skipped.
 */
final class ArticlePage
{
    /**
     * @param list<Article>      $articles
     * @param list<SkippedEntry> $skipped
     */
    public function __construct(
        public readonly array $articles,
        public readonly Pagination $pagination,
        public readonly array $skipped = [],
    ) {
    }
}
