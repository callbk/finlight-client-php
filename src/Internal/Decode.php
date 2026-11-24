<?php

declare(strict_types=1);

namespace Finlight\Internal;

use Finlight\ArticlePage;
use Finlight\FinlightDecodeException;
use Finlight\Pagination;
use Finlight\SkippedEntry;
use Psr\Log\LoggerInterface;

/**
 * Reading list answers: a broken entry is skipped, logged and kept in skipped(); the rest stays.
 *
 * @internal
 */
final class Decode
{
    /**
     * @template T
     *
     * @param array<string, mixed>                       $body
     * @param callable(array<string, mixed>, string): T $read
     *
     * @return array{list<T>, list<SkippedEntry>}
     */
    public static function list(array $body, string $key, callable $read, LoggerInterface $logger): array
    {
        $items = $body[$key] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new FinlightDecodeException(sprintf('finlight: the answer has no "%s" array', $key), $key, $items);
        }
        $good = [];
        $skipped = [];
        foreach ($items as $index => $item) {
            $path = sprintf('%s[%d]', $key, $index);
            try {
                if (!Json::isObject($item)) {
                    throw Wire::fail($path, $item, 'is not an object');
                }
                $good[] = $read($item, $path);
            } catch (FinlightDecodeException $e) {
                $logger->warning(sprintf('finlight: skipped an unreadable entry at %s: %s', $path, $e->getMessage()));
                $skipped[] = new SkippedEntry($index, $item, $e->getMessage());
            }
        }

        return [$good, $skipped];
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function articlePage(array $body, LoggerInterface $logger): ArticlePage
    {
        [$articles, $skipped] = self::list($body, 'articles', static fn (array $o, string $p): \Finlight\Article => \Finlight\Article::fromWire($o, $p), $logger);
        $pagination = $body['pagination'] ?? null;
        if (!Json::isObject($pagination)) {
            throw new FinlightDecodeException('finlight: the search answer has no "pagination" object', 'pagination', $pagination);
        }

        return new ArticlePage($articles, Pagination::fromWire($pagination), $skipped);
    }
}
