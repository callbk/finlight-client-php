<?php

declare(strict_types=1);

namespace Finlight;

use Psr\Log\LoggerInterface;

/**
 * The result of articles->searchAll(). It fetches pages while you iterate.
 *
 * foreach gives the articles; pages() gives whole pages. It can be iterated once: a second pass
 * throws FinlightValidationException instead of searching again. Errors (for example a monthly
 * RateLimitedException) are thrown during the iteration.
 *
 * When maxPages was reached and more results exist, truncated() is true and nextCursor() is the
 * cursor to continue with (pass it as cursor to a new searchAll with the same parameters).
 *
 * @implements \IteratorAggregate<int, Article>
 */
final class SearchAllResult implements \IteratorAggregate
{
    private bool $started = false;

    private bool $truncated = false;

    private int $pagesFetched = 0;

    private ?string $nextCursor = null;

    /**
     * @internal
     *
     * @param \Closure(array<string, mixed>): ArticlePage $fetch
     * @param array<string, mixed>                        $body  the frozen first-page body
     */
    public function __construct(
        private readonly \Closure $fetch,
        private readonly array $body,
        private readonly int $maxPages,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return \Generator<int, Article>
     */
    public function getIterator(): \Generator
    {
        foreach ($this->pages() as $page) {
            foreach ($page->articles as $article) {
                yield $article;
            }
        }
    }

    /**
     * @return \Generator<int, ArticlePage>
     */
    public function pages(): \Generator
    {
        if ($this->started) {
            throw new FinlightValidationException('finlight: a searchAll result can be iterated once; call searchAll again for a new search');
        }
        $this->started = true;

        return $this->walk();
    }

    /** The cursor of the last page fetched, or null when the search is over. */
    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    /** True when the walk stopped at maxPages while more results exist. */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    public function pagesFetched(): int
    {
        return $this->pagesFetched;
    }

    /**
     * @return \Generator<int, ArticlePage>
     */
    private function walk(): \Generator
    {
        $body = $this->body;
        while (true) {
            $page = ($this->fetch)($body);
            ++$this->pagesFetched;
            $cursor = $page->pagination->nextCursor;
            $more = $page->pagination->hasMore === true && $cursor !== null;
            $this->nextCursor = $more ? $cursor : null;
            yield $this->pagesFetched - 1 => $page;
            if (!$more) {
                return;
            }
            if ($this->pagesFetched >= $this->maxPages) {
                $this->truncated = true;
                $this->logger->warning(sprintf('finlight: searchAll stopped after maxPages (%d) pages; continue with nextCursor()', $this->maxPages));

                return;
            }
            $body['cursor'] = $cursor; // only the cursor changes between pages
        }
    }
}
