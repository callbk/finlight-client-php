<?php

declare(strict_types=1);

namespace Finlight\Tests\Conformance;

use Finlight\FinlightValidationException;
use Finlight\Subscribe;
use Finlight\Tests\Support\Kit;
use Finlight\Tests\Support\MockHttpClient;
use Finlight\Tests\Support\TestClient;
use Finlight\Tests\Support\Wire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * R-01 .. R-14: what the SDK sends, checked after serialisation on the injected PSR-18 client.
 */
final class RequestsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function requestCases(): array
    {
        return Kit::cases('fixtures/requests');
    }

    #[DataProvider('requestCases')]
    public function testRequestFixture(string $rel): void
    {
        $fx = Kit::load($rel);
        $sdk = Kit::obj($fx['sdk']);
        $args = Kit::obj($sdk['args']);
        $method = Kit::str($sdk['method']);

        if ($method === 'stream' || $method === 'rawStream') {
            $this->checkSubscribe($fx, $method, $args);

            return;
        }

        $want = Kit::obj($fx['request']);
        $t = new TestClient(['apiKey' => Kit::str(Kit::obj($want['headers'])['X-API-KEY'])]);
        $t->http->push(MockHttpClient::fromFixture(Kit::obj($fx['response'])));

        if ($fx['id'] === 'R-12') {
            try {
                $t->client->articles->search($args);
                self::fail('the unknown key was not rejected');
            } catch (FinlightValidationException $e) {
                self::assertSame('eventType', $e->param());
                self::assertStringContainsString('eventType', $e->getMessage());
            }
            self::assertSame([], $t->http->requests, 'nothing is sent');

            return;
        }

        $result = match ($method) {
            'articles.search' => $t->client->articles->search($args),
            'articles.get' => $t->client->articles->get(Kit::str($args['id']), self::strings($args['include'] ?? null)),
            'articles.getByLink' => $t->client->articles->getByLink(Kit::str($args['link']), self::strings($args['include'] ?? null)),
            'companies.search' => $t->client->companies->search(Kit::str($args['q']), isset($args['mic']) ? Kit::str($args['mic']) : null),
            'companies.getMany' => $t->client->companies->getMany(self::ints($args['ids'])),
            'companies.get' => $t->client->companies->get(self::int($args['companyId']), isset($args['ifNoneMatch']) ? Kit::str($args['ifNoneMatch']) : null),
            'sources.list' => $t->client->sources->list(),
            default => throw new \LogicException('unknown sdk method ' . $method),
        };

        self::assertCount(1, $t->http->requests);
        $sent = $t->http->last();
        self::assertSame($want['method'], $sent->getMethod());
        self::assertSame($want['path'], $sent->getUri()->getPath());
        self::assertSame(
            Wire::query(isset($want['query']) ? Kit::str($want['query']) : ''),
            Wire::query($sent->getUri()->getQuery()),
        );
        self::assertStringNotContainsString('include%5B%5D', $sent->getUri()->getQuery());
        foreach (Kit::obj($want['headers']) as $name => $value) {
            self::assertSame($value, $sent->getHeaderLine($name), $name);
        }
        if (array_key_exists('body', $want)) {
            self::assertSame(Wire::canonical($want['body']), Wire::canonical(Wire::body($sent)));
            if ($want['body'] === []) {
                self::assertSame('{}', (string) $sent->getBody());
            }
        } else {
            self::assertSame('', (string) $sent->getBody());
        }

        $this->checkResult(Kit::str($fx['id']), $result, $fx);
    }

    /**
     * @param array<string, mixed> $fx
     * @param array<string, mixed> $args
     */
    private function checkSubscribe(array $fx, string $method, array $args): void
    {
        $want = Kit::obj($fx['frame']);
        $nonce = Kit::str($want['clientNonce']);
        $json = $method === 'stream' ? Subscribe::enriched($args, $nonce) : Subscribe::raw($args, $nonce);
        $sent = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(Wire::canonical($want), Wire::canonical($sent));
        self::assertIsArray($sent);
        self::assertSame(3, $sent['payloadVersion']);
        self::assertStringContainsString('"payloadVersion":3', $json);
        foreach (['includeContent', 'includeEntities', 'extended', 'action'] as $legacy) {
            self::assertArrayNotHasKey($legacy, $sent);
        }
        if ($method === 'rawStream') {
            self::assertArrayNotHasKey('include', $sent);
        }
        // The server answers with frames the decoder reads (R-13: admitted + initial push; R-14: admitted only).
        $kinds = [];
        foreach (Kit::list(Kit::obj($fx['server'])['frames']) as $frame) {
            $kinds[] = \Finlight\Frame::decode(Kit::json($frame))->kind();
        }
        self::assertSame($fx['id'] === 'R-13' ? ['admitted', 'created'] : ['admitted'], $kinds);
    }

    /**
     * @param array<string, mixed> $fx
     */
    private function checkResult(string $id, mixed $result, array $fx): void
    {
        $body = Kit::obj(Kit::obj($fx['response'])['body'] ?? []);
        switch ($id) {
            case 'R-01':
            case 'R-02':
            case 'R-03':
                self::assertInstanceOf(\Finlight\ArticlePage::class, $result);
                self::assertCount(count(Kit::list($body['articles'])), $result->articles);
                self::assertSame(Kit::obj($body['pagination'])['hasMore'], $result->pagination->hasMore);
                self::assertSame([], $result->skipped);
                break;
            case 'R-04':
            case 'R-05':
                self::assertInstanceOf(\Finlight\Article::class, $result);
                self::assertSame(Kit::obj($body['article'])['id'], $result->id);
                break;
            case 'R-06':
            case 'R-07':
                self::assertInstanceOf(\Finlight\CompanyList::class, $result);
                self::assertCount(count(Kit::list($body['companies'])), $result);
                if ($id === 'R-07') {
                    $private = array_values(array_filter(iterator_to_array($result, false), static fn ($c) => $c->primaryListing === null));
                    self::assertNotEmpty($private);
                    self::assertSame([], $private[0]->listings);
                }
                break;
            case 'R-08':
                self::assertInstanceOf(\Finlight\CompanyRecord::class, $result);
                $headers = Kit::obj(Kit::obj($fx['response'])['headers']);
                self::assertSame($headers['etag'], $result->cacheInfo()?->etag);
                self::assertSame($headers['cache-control'], $result->cacheInfo()?->cacheControl);
                self::assertFalse($result->cacheInfo()?->notModified);
                $mics = array_map(static fn ($l) => $l->mic, $result->listings);
                self::assertSame(array_unique($mics), $mics, 'one listing per exchange');
                self::assertSame($result->primaryListing?->mic, $mics[0], 'primary listing first');
                break;
            case 'R-09':
                self::assertNull($result, '304 with a caller ETag is "not modified"');
                break;
            case 'R-10':
                self::assertInstanceOf(\Finlight\SourceList::class, $result);
                $custom = false;
                $types = [];
                foreach ($result as $source) {
                    self::assertIsBool($source->hasContent);
                    $custom = $custom || $source->isCustom === true;
                    $types[$source->domain] = $source->type;
                }
                self::assertTrue($custom);
                self::assertSame(
                    ['archive.example.org' => null, 'custom.example.net' => \Finlight\SourceType::BLOG, 'news.example.com' => \Finlight\SourceType::FINANCIAL_MEDIA],
                    $types,
                    'source types as sent, null when unclassified',
                );
                break;
            case 'R-11':
                self::assertInstanceOf(\Finlight\SourceList::class, $result);
                self::assertGreaterThan(0, count($result));
                foreach ($result as $source) {
                    self::assertNull($source->hasContent, 'absent hasContent is unknown, not false');
                    self::assertArrayNotHasKey('hasContent', $source->rawJson());
                    self::assertNotTrue($source->isCustom);
                }
                break;
            default:
                self::fail('no result check for ' . $id);
        }
    }

    /**
     * @return list<string>|null
     */
    private static function strings(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return array_map(Kit::str(...), Kit::list($value));
    }

    /**
     * @return list<int>
     */
    private static function ints(mixed $value): array
    {
        return array_map(self::int(...), Kit::list($value));
    }

    private static function int(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \LogicException('not an int');
        }

        return $value;
    }
}
