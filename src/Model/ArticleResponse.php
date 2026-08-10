<?php

declare(strict_types=1);

namespace Finlight\Model;

use Finlight\Internal\Value;

/**
 * One page of article results.
 *
 * @implements \IteratorAggregate<int, Article>
 */
final class ArticleResponse implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Article> $articles
     */
    public function __construct(
        public readonly string $status,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly array $articles,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $articles = Value::nullableObjectList($data, 'articles') ?? [];

        return new self(
            status: Value::string($data, 'status', 'ArticleResponse'),
            page: Value::int($data, 'page', 'ArticleResponse'),
            pageSize: Value::int($data, 'pageSize', 'ArticleResponse'),
            articles: array_map(static fn (array $article): Article => Article::fromArray($article), $articles),
        );
    }

    /**
     * @return \ArrayIterator<int, Article>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->articles);
    }

    public function count(): int
    {
        return count($this->articles);
    }
}
