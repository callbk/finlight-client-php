<?php

declare(strict_types=1);

namespace Finlight\Tests\Unit;

use Finlight\FinlightValidationException;
use Finlight\Subscribe;
use Finlight\Tests\Support\MockHttpClient;
use Finlight\Tests\Support\TestClient;
use Finlight\Tests\Support\Wire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * R-REQ-1 .. R-REQ-3, R-SCOPE-1: what goes on the wire, and what is refused before it.
 */
final class ParamsTest extends TestCase
{
    private const PAGE = ['articles' => [], 'pagination' => ['limit' => 1, 'returned' => 0, 'hasMore' => false, 'nextCursor' => null]];

    /** Every search parameter, set. */
    private const ALL_SEARCH = [
        'query' => 'q', 'sources' => ['a'], 'optInSources' => ['b'], 'excludeSources' => ['c'],
        'from' => '2026-01-01', 'to' => '2026-02-01', 'languages' => ['en'], 'tickers' => ['T'],
        'countries' => ['US'], 'categories' => ['markets'], 'hasContent' => false, 'roles' => ['target'],
        'minRelevance' => 0, 'eventTypes' => ['mna'], 'eventDirections' => ['positive'],
        'minImportance' => 'low', 'sectors' => ['Energy'], 'companyCountries' => ['US'],
        'exchanges' => ['XNAS'], 'orderBy' => 'publishedAt', 'order' => 'asc', 'limit' => 1,
        'cursor' => 'c1', 'include' => ['content', 'companies', 'events'],
    ];

    private const LEGACY = ['includeContent', 'includeEntities', 'extended', 'pageSize', 'publishDate', 'isUpdate'];

    /**
     * @param array<string, mixed> $params
     *
     * @return array{text: string, body: mixed}
     */
    private function search(array $params): array
    {
        $t = new TestClient();
        $t->http->push(MockHttpClient::json(200, self::PAGE));
        $t->client->articles->search($params);

        return ['text' => (string) $t->http->last()->getBody(), 'body' => Wire::body($t->http->last())];
    }

    public function testEmptySearchSendsExactlyAnEmptyObject(): void
    {
        self::assertSame('{}', $this->search([])['text']);
    }

    public function testEveryKeyIsSentUnderItsWireNameAndNoLegacyKey(): void
    {
        $sent = $this->search(self::ALL_SEARCH);
        $body = \Finlight\Tests\Support\Kit::obj($sent['body']);
        self::assertSame(array_keys(self::ALL_SEARCH), array_keys($body));
        self::assertFalse($body['hasContent'], 'an explicit false is sent');
        self::assertSame(0, $body['minRelevance'], 'an explicit 0 is sent');
        foreach (self::LEGACY as $key) {
            self::assertStringNotContainsStringIgnoringCase($key, $sent['text']);
        }
        foreach ([Subscribe::enriched(['include' => ['content'], 'query' => 'q', 'sources' => ['a'], 'excludeSources' => ['b'], 'optInSources' => ['c'], 'language' => 'en', 'tickers' => ['T'], 'countries' => ['US'], 'categories' => ['x'], 'excludeEmptyContent' => true, 'includeUpdates' => true]), Subscribe::raw(['query' => 'q', 'sources' => ['a'], 'excludeSources' => ['b'], 'language' => 'en', 'includeUpdates' => false])] as $frame) {
            foreach (self::LEGACY as $key) {
                self::assertStringNotContainsStringIgnoringCase($key, $frame);
            }
        }
    }

    public function testNullMeansNotSetAndNoDefaultsAreAdded(): void
    {
        $sent = $this->search(['query' => 'x', 'limit' => null, 'languages' => null]);
        self::assertSame('{"query":"x"}', $sent['text']);
    }

    public function testUnknownVocabularyValuesGoOutUnchanged(): void
    {
        $sent = $this->search(['eventTypes' => ['brand_new_type'], 'orderBy' => 'somethingNew']);
        self::assertSame(['eventTypes' => ['brand_new_type'], 'orderBy' => 'somethingNew'], $sent['body']);
    }

    public function testTimesAreFormattedInUtcWithMillisecondsAndStringsAreKept(): void
    {
        $from = new \DateTime('2026-03-01 12:00:00.5', new \DateTimeZone('Europe/Berlin'));
        $sent = $this->search(['from' => $from, 'to' => '2026-03-02']);
        self::assertSame(['from' => '2026-03-01T11:00:00.500Z', 'to' => '2026-03-02'], $sent['body']);
    }

    public function testFormattingIgnoresTheLocale(): void
    {
        $old = setlocale(\LC_ALL, '0');
        try {
            foreach (['de_DE.UTF-8', 'tr_TR.UTF-8', 'de_DE', 'tr_TR'] as $locale) {
                setlocale(\LC_ALL, $locale);
                $sent = $this->search(['minRelevance' => 0.5, 'from' => new \DateTimeImmutable('2026-01-01T00:00:00Z')]);
                self::assertSame('{"from":"2026-01-01T00:00:00.000Z","minRelevance":0.5}', $sent['text']);
                $t = new TestClient();
                $t->http->push(MockHttpClient::json(200, ['companies' => []]));
                $t->client->companies->getMany([1, 2]);
                self::assertSame('ids=1%2C2', $t->http->last()->getUri()->getQuery());
            }
        } finally {
            setlocale(\LC_ALL, is_string($old) ? $old : 'C');
        }
    }

    public function testIncludeIsOneCommaJoinedQueryParameterAndTheLinkIsEncodedOnce(): void
    {
        $t = new TestClient();
        $article = ['id' => 'i', 'link' => 'l', 'source' => ['domain' => 'd'], 'title' => 't'];
        $t->http->push(MockHttpClient::json(200, ['article' => $article]));
        $t->http->push(MockHttpClient::json(200, ['article' => $article]));
        $t->http->push(MockHttpClient::json(200, ['article' => $article]));
        $t->client->articles->getByLink('https://x.example/a b?c=1&d=%41', ['content', 'events']);
        self::assertSame('link=https%3A%2F%2Fx.example%2Fa%20b%3Fc%3D1%26d%3D%2541&include=content%2Cevents', $t->http->last()->getUri()->getQuery());
        $t->client->articles->get('abc', []);
        self::assertSame('', $t->http->last()->getUri()->getQuery(), 'an empty include is left out');
        $t->client->articles->get('abc');
        self::assertSame('/v3/articles/abc', $t->http->last()->getUri()->getPath());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function badSearches(): array
    {
        return [
            'unknown key' => [['eventType' => ['mna']], 'eventType'],
            'legacy key' => [['includeContent' => true], 'includeContent'],
            'snake key' => [['event_types' => ['mna']], 'event_types'],
            'limit true' => [['limit' => true], 'limit'],
            'limit string' => [['limit' => '20'], 'limit'],
            'limit 0' => [['limit' => 0], 'limit'],
            'limit 101' => [['limit' => 101], 'limit'],
            'limit float' => [['limit' => 20.0], 'limit'],
            'empty languages' => [['languages' => []], 'languages'],
            'minRelevance > 1' => [['minRelevance' => 1.5], 'minRelevance'],
            'minRelevance string' => [['minRelevance' => '0.5'], 'minRelevance'],
            'four includes' => [['include' => ['content', 'companies', 'events', 'content']], 'include'],
            'include string' => [['include' => 'content'], 'include'],
            'tickers map' => [['tickers' => ['a' => 'AAPL']], 'tickers'],
            'hasContent string' => [['hasContent' => 'true'], 'hasContent'],
            'from int' => [['from' => 1700000000], 'from'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('badSearches')]
    public function testBadSearchIsRefusedBeforeAnyRequest(array $params, string $param): void
    {
        $t = new TestClient();
        foreach (['search', 'searchAll'] as $method) {
            try {
                $t->client->articles->{$method}($params);
                self::fail('not refused: ' . $method);
            } catch (FinlightValidationException $e) {
                self::assertSame($param, $e->param());
                self::assertStringContainsString($param, $e->getMessage());
                self::assertInstanceOf(\InvalidArgumentException::class, $e);
                self::assertSame('validation', $e->kind());
            }
        }
        self::assertSame([], $t->http->requests);
    }

    public function testLookupShapesAreCheckedLocally(): void
    {
        $t = new TestClient();
        $calls = [
            'ids' => [
                static fn () => $t->client->companies->getMany([]),
                static fn () => $t->client->companies->getMany(range(1, 11)),
                static fn () => $t->client->companies->getMany([0]),
                static fn () => $t->client->companies->getMany(['1']), // @phpstan-ignore argument.type
            ],
            'q' => [static fn () => $t->client->companies->search(str_repeat('é', 201))],
            'mic' => [static fn () => $t->client->companies->search('X', str_repeat('M', 33))],
            'link' => [static fn () => $t->client->articles->getByLink('')],
            'id' => [static fn () => $t->client->articles->get('')],
            'include' => [static fn () => $t->client->articles->get('x', ['a', 'b', 'c', 'd'])],
            'companyId' => [static fn () => $t->client->companies->get(0)],
        ];
        foreach ($calls as $param => $list) {
            foreach ($list as $call) {
                try {
                    $call();
                    self::fail('not refused: ' . $param);
                } catch (FinlightValidationException $e) {
                    self::assertSame($param, $e->param());
                }
            }
        }
        self::assertSame([], $t->http->requests);
        // 200 characters are fine.
        $t->http->push(MockHttpClient::json(200, ['companies' => []]));
        $t->client->companies->search(str_repeat('é', 200), str_repeat('M', 32));
        self::assertCount(1, $t->http->requests);
    }

    public function testSubscribeIsBuiltFromAFixedKeyList(): void
    {
        $frame = json_decode(Subscribe::enriched(['query' => 'ticker:NVDA']), true);
        self::assertIsArray($frame);
        self::assertSame(['payloadVersion', 'query', 'clientNonce'], array_keys($frame));
        self::assertSame(3, $frame['payloadVersion']);
        self::assertIsString($frame['clientNonce']);

        foreach (['payloadVersion' => '3', 'clientNonce' => 'x', 'includeContent' => true, 'includeEntities' => true, 'extended' => true, 'action' => 'ping', 'type' => 'ping', 'languages' => ['en']] as $key => $value) {
            try {
                Subscribe::enriched([$key => $value, 'query' => 'q']);
                self::fail('not refused: ' . $key);
            } catch (FinlightValidationException $e) {
                self::assertSame($key, $e->param());
            }
        }
        foreach (['include' => ['content'], 'tickers' => ['A'], 'countries' => ['US'], 'optInSources' => ['a'], 'categories' => ['x'], 'excludeEmptyContent' => true] as $key => $value) {
            try {
                Subscribe::raw([$key => $value]);
                self::fail('raw accepted ' . $key);
            } catch (FinlightValidationException $e) {
                self::assertSame($key, $e->param());
            }
        }
        self::assertSame('{"payloadVersion":3,"clientNonce":"n"}', Subscribe::raw([], 'n'));
        self::assertSame('{"payloadVersion":3,"clientNonce":"n"}', Subscribe::enriched(['include' => []], 'n'), 'an empty include is left out');
        self::assertSame('{"payloadVersion":3,"includeUpdates":false,"clientNonce":"n"}', Subscribe::enriched(['includeUpdates' => false], 'n'));
    }
}
