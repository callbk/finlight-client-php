<?php

declare(strict_types=1);

namespace Finlight\Request;

use Finlight\Model\ArticleCategory;
use Finlight\Model\OrderBy;
use Finlight\Model\SortOrder;

final class GetArticlesParams
{
    /**
     * @param string|null                $query          Boolean operators, field filters (ticker:, source:, isin:) and quoted phrases.
     * @param list<string>|null          $sources        Restricts to these sources, replacing the default set.
     * @param list<string>|null          $tickers
     * @param list<string>|null          $optInSources   Added on top of the default set.
     * @param list<string>|null          $excludeSources
     * @param string|null                $from           YYYY-MM-DD or ISO 8601.
     * @param string|null                $to             YYYY-MM-DD or ISO 8601.
     * @param string|null                $language       ISO 639-1, defaults to en server-side.
     * @param int|null                   $pageSize       1-100.
     * @param list<string>|null          $countries      ISO 3166-1 alpha-2.
     * @param list<ArticleCategory>|null $categories
     */
    public function __construct(
        public readonly ?string $query = null,
        public readonly ?array $sources = null,
        public readonly ?array $tickers = null,
        public readonly ?array $optInSources = null,
        public readonly ?array $excludeSources = null,
        public readonly ?bool $includeContent = null,
        public readonly ?bool $includeEntities = null,
        public readonly ?bool $excludeEmptyContent = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?string $language = null,
        public readonly ?OrderBy $orderBy = null,
        public readonly ?SortOrder $order = null,
        public readonly ?int $pageSize = null,
        public readonly ?int $page = null,
        public readonly ?array $countries = null,
        public readonly ?array $categories = null,
    ) {
    }

    /**
     * Unset fields are omitted so server defaults apply.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'query' => $this->query,
            'sources' => $this->sources,
            'tickers' => $this->tickers,
            'optInSources' => $this->optInSources,
            'excludeSources' => $this->excludeSources,
            'includeContent' => $this->includeContent,
            'includeEntities' => $this->includeEntities,
            'excludeEmptyContent' => $this->excludeEmptyContent,
            'from' => $this->from,
            'to' => $this->to,
            'language' => $this->language,
            'orderBy' => $this->orderBy?->value,
            'order' => $this->order?->value,
            'pageSize' => $this->pageSize,
            'page' => $this->page,
            'countries' => $this->countries,
            'categories' => $this->categories === null
                ? null
                : array_map(static fn (ArticleCategory $category): string => $category->value, $this->categories),
        ];

        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }
}
