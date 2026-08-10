<?php

declare(strict_types=1);

namespace Finlight\Tests\Service;

use Finlight\Config;
use Finlight\FinlightClient;
use Finlight\Model\ArticleCategory;
use Finlight\Model\OrderBy;
use Finlight\Model\SortOrder;
use Finlight\Request\GetArticleByLinkParams;
use Finlight\Request\GetArticlesParams;
use Finlight\Tests\Support\Fixtures;
use Finlight\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ServiceTest extends TestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    public function testFetchArticlesPostsFilteredPayload(): void
    {
        $this->http->push(self::json(Fixtures::articleResponse()));

        $response = $this->client()->articles->fetchArticles(new GetArticlesParams(
            query: '(ticker:AAPL OR ticker:NVDA) AND "earnings"',
            tickers: ['AAPL'],
            includeEntities: true,
            orderBy: OrderBy::PublishDate,
            order: SortOrder::Desc,
            pageSize: 20,
            categories: [ArticleCategory::Markets, ArticleCategory::Economy],
        ));

        self::assertCount(1, $response->articles);
        self::assertSame('Apple beats quarterly expectations', $response->articles[0]->title);

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.finlight.me/v2/articles', (string) $request->getUri());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['AAPL'], $body['tickers']);
        self::assertTrue($body['includeEntities']);
        self::assertSame('publishDate', $body['orderBy']);
        self::assertSame('DESC', $body['order']);
        self::assertSame(['markets', 'economy'], $body['categories']);

        // Unset filters are omitted so server defaults apply.
        self::assertArrayNotHasKey('sources', $body);
        self::assertArrayNotHasKey('includeContent', $body);
        self::assertArrayNotHasKey('page', $body);
    }

    public function testFetchArticleByLinkUsesGet(): void
    {
        $this->http->push(self::json(Fixtures::article()));

        $article = $this->client()->articles->fetchArticleByLink(new GetArticleByLinkParams(
            link: 'https://www.reuters.com/markets/example-article',
            includeContent: true,
        ));

        self::assertSame('Apple beats quarterly expectations', $article->title);

        $request = $this->http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v2/articles/by-link', $request->getUri()->getPath());
        self::assertSame('', (string) $request->getBody());
    }

    public function testGetSourcesMapsTheList(): void
    {
        $this->http->push(self::json(Fixtures::sources()));

        $sources = $this->client()->sources->getSources();

        self::assertCount(2, $sources);
        self::assertSame('www.reuters.com', $sources[0]->domain);
        self::assertTrue($sources[0]->isDefaultSource);
        self::assertFalse($sources[1]->isDefaultSource);

        self::assertSame('/v2/sources', $this->http->lastRequest()->getUri()->getPath());
    }

    public function testClientAcceptsABareApiKey(): void
    {
        $this->http->push(self::json(Fixtures::sources()));

        $factory = new Psr17Factory();
        $client = new FinlightClient('just-the-key', $this->http, $factory, $factory);

        $client->sources->getSources();

        self::assertSame('just-the-key', $this->http->lastRequest()->getHeaderLine('X-API-KEY'));
    }

    public function testEmptyApiKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apiKey must not be empty');

        new Config('   ');
    }

    private function client(): FinlightClient
    {
        $factory = new Psr17Factory();

        return new FinlightClient(
            new Config(apiKey: 'test-key', retryCount: 1),
            $this->http,
            $factory,
            $factory,
        );
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private static function json(array $payload): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }
}
