<?php

declare(strict_types=1);

namespace Finlight\Service;

use Finlight\Exception\MalformedResponseException;
use Finlight\Http\ApiClient;
use Finlight\Model\Article;
use Finlight\Model\ArticleResponse;
use Finlight\Request\GetArticleByLinkParams;
use Finlight\Request\GetArticlesParams;

final class ArticleService
{
    public function __construct(private readonly ApiClient $apiClient)
    {
    }

    /**
     * @throws \Finlight\Exception\FinlightException
     */
    public function fetchArticles(GetArticlesParams $params): ArticleResponse
    {
        $data = $this->apiClient->request('POST', '/v2/articles', $params->toArray());

        /** @var array<string, mixed> $data */
        return ArticleResponse::fromArray($data);
    }

    /**
     * @throws \Finlight\Exception\NotFoundException When the link is not in the index.
     * @throws \Finlight\Exception\FinlightException
     */
    public function fetchArticleByLink(GetArticleByLinkParams $params): Article
    {
        $data = $this->apiClient->request('GET', '/v2/articles/by-link', $params->toArray());

        // The endpoint wraps the article in a {"status": ..., "article": {...}} envelope.
        // A bare article is accepted too, since it has no "article" field of its own.
        $article = $data['article'] ?? $data;

        if (!is_array($article)) {
            throw new MalformedResponseException('Article: expected an object at "article".');
        }

        /** @var array<string, mixed> $article */
        return Article::fromArray($article);
    }
}
