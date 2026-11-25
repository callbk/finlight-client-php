<?php

declare(strict_types=1);

namespace Finlight\Tests\Unit;

use Finlight\FinlightClient;
use Finlight\FinlightDecodeException;
use Finlight\FinlightNetworkException;
use Finlight\FinlightTimeoutException;
use Finlight\InternalServerException;
use Finlight\NotFoundException;
use Finlight\RateLimitedException;
use Finlight\ValidationFailedException;
use Finlight\Tests\Support\MockHttpClient;
use Finlight\Tests\Support\TestClient;
use GuzzleHttp\Exception\ConnectException;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * R-CONFIG-1, R-RETRY-1, R-ERR-1: transport, retries and error reading.
 */
final class HttpTest extends TestCase
{
    private const PAGE = ['articles' => [], 'pagination' => ['limit' => 1, 'returned' => 0, 'hasMore' => true, 'nextCursor' => 'c']];

    private static function burst(?string $retryAfter = null): \Psr\Http\Message\ResponseInterface
    {
        $headers = ['Content-Type' => 'application/problem+json'];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = $retryAfter;
        }

        return MockHttpClient::json(429, ['type' => 't', 'title' => 'Too many requests', 'status' => 429, 'detail' => 'Throttling Exception: Exceeded rate limit of 5 requests per 10 seconds.', 'code' => 'RATE_LIMITED', 'limitType' => 'burst'], $headers);
    }

    private static function monthly(): \Psr\Http\Message\ResponseInterface
    {
        return MockHttpClient::json(429, ['type' => 't', 'title' => 'Too many requests', 'status' => 429, 'detail' => 'Throttling Exception: Exceeded token limit of 10 for the current period.', 'code' => 'RATE_LIMITED', 'limitType' => 'monthly'], ['Content-Type' => 'application/problem+json']);
    }

    public function testEveryRequestIdentifiesTheSdk(): void
    {
        $t = new TestClient(['baseUrl' => 'http://127.0.0.1:9/base/']);
        $t->http->push(MockHttpClient::json(200, ['sources' => []]));
        $t->client->sources->list();
        $r = $t->http->last();
        self::assertSame('http://127.0.0.1:9/base/v3/sources', (string) $r->getUri(), 'a trailing slash does not matter');
        self::assertSame('finlight-client-php/' . FinlightClient::VERSION, $r->getHeaderLine('User-Agent'));
        self::assertSame(FinlightClient::VERSION, $r->getHeaderLine('x-client-version'));
        self::assertDoesNotMatchRegularExpression('/^v/', FinlightClient::VERSION);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $r->getHeaderLine('X-Request-Id'));
        self::assertSame(\Finlight\Tests\Support\Kit::KEY, $r->getHeaderLine('X-API-KEY'));
    }

    public function testBurstWaitsRetryAfterOnceAndKeepsTheRequestId(): void
    {
        $t = new TestClient();
        $t->http->push(self::burst('3'))->push(MockHttpClient::json(200, self::PAGE));
        $t->client->articles->search();
        self::assertSame([3.0], $t->clock->sleeps);
        self::assertCount(2, $t->http->requests);
        self::assertSame($t->http->requests[0]->getHeaderLine('X-Request-Id'), $t->http->requests[1]->getHeaderLine('X-Request-Id'));
    }

    public function testBurstWithoutRetryAfterWaitsTenSecondsAndDoesNotCountAgainstMaxRetries(): void
    {
        $t = new TestClient(['maxRetries' => 0]);
        $t->http->push(self::burst())->push(MockHttpClient::json(200, self::PAGE));
        $t->client->articles->search();
        self::assertSame([10.0], $t->clock->sleeps);
    }

    public function testBurstRetryHappensOnlyOnce(): void
    {
        $t = new TestClient();
        $t->http->push(self::burst('1'))->push(self::burst('1'));
        $this->expectException(RateLimitedException::class);
        try {
            $t->client->articles->search();
        } finally {
            self::assertCount(2, $t->http->requests);
        }
    }

    public function testBurstRetryCanBeTurnedOff(): void
    {
        $t = new TestClient(['retryOnBurst' => 'off']);
        $t->http->push(self::burst('7'));
        try {
            $t->client->articles->search();
            self::fail('no error');
        } catch (RateLimitedException $e) {
            self::assertSame('burst', $e->limitType());
            self::assertSame(7.0, $e->retryAfter());
        }
        self::assertSame([], $t->clock->sleeps);
        self::assertCount(1, $t->http->requests);
    }

    public function testMonthlyFailsAtOnceAlsoInsideSearchAll(): void
    {
        $t = new TestClient();
        $t->http->push(MockHttpClient::json(200, self::PAGE))->push(self::monthly());
        $result = $t->client->articles->searchAll(['query' => 'x']);
        try {
            foreach ($result as $_) {
            }
            self::fail('no error');
        } catch (RateLimitedException $e) {
            self::assertSame('monthly', $e->limitType());
        }
        self::assertCount(2, $t->http->requests);
        self::assertSame([], $t->clock->sleeps, 'never waits for a new quota');
        self::assertSame(1, $result->pagesFetched());
    }

    public function testNetworkErrorsAreRetriedAndWrapped(): void
    {
        $t = new TestClient();
        $failure = new ConnectException('Connection refused for URI https://api.test.invalid', new Request('GET', '/'));
        $t->http->push($failure)->push($failure)->push(MockHttpClient::json(200, ['sources' => []]));
        $t->client->sources->list();
        self::assertCount(3, $t->http->requests);
        self::assertCount(2, $t->clock->sleeps);
        self::assertCount(1, array_unique(array_map(static fn ($r) => $r->getHeaderLine('X-Request-Id'), $t->http->requests)));

        $t = new TestClient(['maxRetries' => 1]);
        $t->http->push($failure)->push($failure);
        try {
            $t->client->sources->list();
            self::fail('no error');
        } catch (FinlightNetworkException $e) {
            self::assertSame('network', $e->kind());
            self::assertSame($failure, $e->getPrevious(), 'the original error stays reachable');
        }
    }

    public function testTimeoutsAreTheirOwnKind(): void
    {
        $t = new TestClient(['maxRetries' => 0]);
        $t->http->push(new ConnectException('cURL error 28: Operation timed out after 30000 milliseconds', new Request('GET', '/')));
        try {
            $t->client->sources->list();
            self::fail('no error');
        } catch (FinlightTimeoutException $e) {
            self::assertSame('timeout', $e->kind());
            self::assertInstanceOf(FinlightNetworkException::class, $e);
        }
    }

    public function testTotalTimeoutCapsRetriesAndTheBurstWait(): void
    {
        $t = new TestClient(['totalTimeout' => 5.0]);
        $t->http->push(self::burst('7'));
        $this->expectException(RateLimitedException::class);
        try {
            $t->client->articles->search();
        } finally {
            self::assertSame([], $t->clock->sleeps, 'a 7 s burst wait does not fit into 5 s');
        }
    }

    public function testServerErrorsBackOffThenFail(): void
    {
        $t = new TestClient();
        $t->http->always(static fn () => MockHttpClient::json(503, ['type' => 't', 'title' => 'x', 'status' => 503, 'code' => 'INTERNAL_ERROR', 'detail' => 'The request could not be processed.'], ['Content-Type' => 'application/problem+json']));
        try {
            $t->client->articles->search();
            self::fail('no error');
        } catch (InternalServerException $e) {
            self::assertSame(503, $e->status());
        }
        self::assertCount(4, $t->http->requests, 'the first try and 3 retries');
        foreach ($t->clock->sleeps as $i => $s) {
            self::assertLessThanOrEqual(0.5 * (2 ** $i), $s);
            self::assertGreaterThanOrEqual(0.25 * (2 ** $i), $s);
        }
    }

    public function testEachCallHasItsOwnRequestId(): void
    {
        $t = new TestClient();
        $t->http->always(static fn () => MockHttpClient::json(200, ['sources' => []]));
        $t->client->sources->list();
        $t->client->sources->list();
        self::assertNotSame($t->http->requests[0]->getHeaderLine('X-Request-Id'), $t->http->requests[1]->getHeaderLine('X-Request-Id'));
    }

    public function testNestJsArrayMessageAndRequestIdFallback(): void
    {
        $t = new TestClient();
        $t->http->push(MockHttpClient::json(400, ['statusCode' => 400, 'message' => ['a must be x', 'b must be y'], 'error' => 'Bad Request']));
        try {
            $t->client->articles->search();
            self::fail('no error');
        } catch (ValidationFailedException $e) {
            self::assertNull($e->code());
            self::assertSame('a must be x; b must be y', $e->detail());
            self::assertCount(2, $e->errors());
            self::assertSame($t->http->last()->getHeaderLine('X-Request-Id'), $e->requestId());
            self::assertSame(0, $e->getCode());
        }
    }

    public function testRateLimitWithoutLimitTypeIsReadFromDetail(): void
    {
        foreach (['Exceeded token limit of 5' => 'monthly', 'Exceeded rate limit of 5' => 'burst', 'Something else' => 'unknown'] as $detail => $want) {
            $t = new TestClient(['retryOnBurst' => 'off']);
            $t->http->push(MockHttpClient::json(429, ['statusCode' => 429, 'message' => 'ThrottlerException: ' . $detail]));
            try {
                $t->client->articles->search();
                self::fail('no error');
            } catch (RateLimitedException $e) {
                self::assertSame($want, $e->limitType());
            }
        }
    }

    public function testNotFoundIsAnErrorNotAnEmptyResult(): void
    {
        $t = new TestClient();
        $t->http->push(MockHttpClient::json(404, ['type' => 't', 'title' => 'Not found', 'status' => 404, 'code' => 'NOT_FOUND', 'detail' => "No article with id 'x'."], ['Content-Type' => 'application/problem+json']));
        $this->expectException(NotFoundException::class);
        $t->client->articles->get('x');
    }

    public function testBrokenAnswersAreDecodeErrors(): void
    {
        $t = new TestClient();
        $t->http->push(MockHttpClient::json(200, '<html>'));
        try {
            $t->client->sources->list();
            self::fail('no error');
        } catch (FinlightDecodeException $e) {
            self::assertSame('decode', $e->kind());
        }
        $t->http->push(MockHttpClient::json(200, '[]'));
        $this->expectException(FinlightDecodeException::class);
        $t->client->sources->list();
    }

    public function testAnswersOverTheSizeLimitAreRefused(): void
    {
        $t = new TestClient(['maxResponseBytes' => 100]);
        $t->http->push(MockHttpClient::json(200, ['sources' => [], 'pad' => str_repeat('x', 200)]));
        $this->expectException(FinlightDecodeException::class);
        $t->client->sources->list();
    }

    public function testCompaniesGetKeepsNoCacheAndSendsOnlyTheCallersEtag(): void
    {
        $record = ['companyId' => 7, 'name' => 'Seven Inc.', 'isins' => [], 'listings' => []];
        $t = new TestClient();
        $t->http->always(static fn () => MockHttpClient::json(200, ['company' => $record], ['ETag' => '"abc"', 'Cache-Control' => 'public, max-age=86400']));
        $first = $t->client->companies->get(7);
        $info = $first->cacheInfo();
        self::assertNotNull($info);
        self::assertSame('"abc"', $info->etag);
        self::assertSame('public, max-age=86400', $info->cacheControl);
        $second = $t->client->companies->get(7);
        self::assertCount(2, $t->http->requests, 'every call is a request');
        self::assertFalse($t->http->last()->hasHeader('If-None-Match'), 'no If-None-Match without the caller ETag');
        self::assertSame('Seven Inc.', $second->name);

        $t = new TestClient();
        $t->http->push(MockHttpClient::json(304, null, ['ETag' => '"abc"']));
        self::assertNull($t->client->companies->get(7, ifNoneMatch: '"abc"'));
        self::assertSame('"abc"', $t->http->last()->getHeaderLine('If-None-Match'));
    }

    public function testA304WithoutTheCallersEtagIsAnError(): void
    {
        $t = new TestClient();
        $t->http->push(MockHttpClient::json(304, null, ['ETag' => '"abc"']));
        $this->expectException(\Finlight\FinlightApiException::class);
        $t->client->companies->get(7);
    }
}
