<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Decode;
use Finlight\Internal\Json;
use Finlight\Internal\Params;
use Finlight\Internal\Transport;

/**
 * $client->articles: search, searchAll, get, getByLink.
 *
 * Parameters are arrays with the wire keys of the API (camelCase). Only the keys you set are sent;
 * an unknown key throws FinlightValidationException before anything is sent.
 */
final class ArticlesService
{
    /**
     * @internal
     */
    public function __construct(private readonly Transport $transport, private readonly int $maxPages)
    {
    }

    /**
     * One page of POST /v3/articles/search. Every parameter is optional; [] sends {}.
     *
     * Keys: query, sources, optInSources, excludeSources, sourceTypes, excludeSourceTypes, from, to,
     * languages, tickers, countries, categories, hasContent, roles, minRelevance, eventTypes,
     * eventDirections, minImportance, sectors, companyCountries, exchanges, orderBy, order, limit,
     * cursor, include. sourceTypes and excludeSourceTypes take SourceType values; articles of an
     * unclassified source never match sourceTypes and are kept by excludeSourceTypes.
     * from and to take a string (sent unchanged) or a DateTimeInterface (sent as UTC ISO 8601).
     *
     * @param array<string, mixed> $params
     */
    public function search(array $params = []): ArticlePage
    {
        $body = Params::build(Params::SEARCH, $params, 'search');

        return $this->page($body);
    }

    /**
     * Walk the pages of a search. The request is checked and frozen now; later pages only add the
     * cursor. Stops when the search is over or after maxPages pages (default from the config),
     * because every page costs quota. Iterate it once: foreach gives articles, pages() gives pages.
     *
     * @param array<string, mixed> $params
     */
    public function searchAll(array $params = [], ?int $maxPages = null): SearchAllResult
    {
        $body = Params::build(Params::SEARCH, $params, 'search');
        $limit = $maxPages ?? $this->maxPages;
        if ($limit < 1) {
            throw new FinlightValidationException('finlight: maxPages must be at least 1', 'maxPages');
        }

        return new SearchAllResult(fn (array $page): ArticlePage => $this->page($page), $body, $limit, $this->transport->logger());
    }

    /**
     * GET /v3/articles/{id}. A miss throws NotFoundException (also for articles indexed before ids
     * existed: use getByLink for those).
     *
     * @param list<string>|null $include
     */
    public function get(string $id, ?array $include = null): Article
    {
        if ($id === '') {
            throw new FinlightValidationException('finlight: id must not be empty', 'id');
        }
        $query = Params::includeQuery($include);
        $response = $this->transport->call('GET', '/v3/articles/' . rawurlencode($id), $query);

        return Article::fromJson($response->jsonObject('article')['article'] ?? null, 'article');
    }

    /**
     * GET /v3/articles/by-link. The link must match the stored link exactly. A miss throws
     * NotFoundException.
     *
     * @param list<string>|null $include
     */
    public function getByLink(string $link, ?array $include = null): Article
    {
        if ($link === '') {
            throw new FinlightValidationException('finlight: link must not be empty', 'link');
        }
        $query = array_merge([['link', $link]], Params::includeQuery($include));
        $response = $this->transport->call('GET', '/v3/articles/by-link', $query);

        return Article::fromJson($response->jsonObject('article')['article'] ?? null, 'article');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function page(array $body): ArticlePage
    {
        $json = Json::encodeRequest($body === [] ? new \stdClass() : $body);
        $response = $this->transport->call('POST', '/v3/articles/search', [], $json);

        return Decode::articlePage($response->jsonObject('search'), $this->transport->logger());
    }
}
