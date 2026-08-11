<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

/**
 * Sample API payloads.
 */
final class Fixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function article(): array
    {
        return [
            'link' => 'https://www.reuters.com/markets/example-article',
            'title' => 'Apple beats quarterly expectations',
            'publishDate' => '2026-08-10T09:30:00Z',
            'source' => 'www.reuters.com',
            'language' => 'en',
            'sentiment' => 'positive',
            'confidence' => '0.87',
            'summary' => 'Revenue came in above consensus.',
            'images' => ['https://images.example/1.jpg'],
            'categories' => ['markets', 'business'],
            'countries' => ['US'],
            'createdAt' => '2026-08-10T09:31:00Z',
            'companies' => [
                [
                    'companyId' => 42,
                    'name' => 'Apple Inc.',
                    'ticker' => 'AAPL',
                    'confidence' => '0.95',
                    'isin' => 'US0378331005',
                    'isins' => ['US0378331005'],
                    'sector' => 'Technology',
                    'primaryListing' => [
                        'ticker' => 'AAPL',
                        'exchangeCode' => 'XNAS',
                        'exchangeCountry' => 'US',
                    ],
                    'otherListings' => [
                        [
                            'ticker' => 'APC',
                            'exchangeCode' => 'XETR',
                            'exchangeCountry' => 'DE',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function articleResponse(): array
    {
        return [
            'status' => 'ok',
            'page' => 1,
            'pageSize' => 20,
            'articles' => [self::article()],
        ];
    }

    /**
     * The by-link endpoint wraps the article in an envelope.
     *
     * @return array<string, mixed>
     */
    public static function articleByLinkResponse(): array
    {
        return [
            'status' => 'ok',
            'article' => self::article(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function sources(): array
    {
        return [
            [
                'domain' => 'www.reuters.com',
                'isDefaultSource' => true,
                'isContentAvailable' => false,
                'originCountry' => 'GB',
                'languages' => ['en'],
            ],
            [
                'domain' => 'www.handelsblatt.com',
                'isDefaultSource' => false,
                'languages' => ['de'],
                'isCustomSource' => true,
            ],
        ];
    }
}
