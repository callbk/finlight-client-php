<?php

declare(strict_types=1);

namespace Finlight\Tests\Http;

use Finlight\Config;
use Finlight\Exception\ApiException;
use Finlight\Exception\AuthenticationException;
use Finlight\Exception\NotFoundException;
use Finlight\Exception\RateLimitException;
use Finlight\Exception\TransportException;
use Finlight\Http\ApiClient;
use Finlight\Tests\Support\FakeClientException;
use Finlight\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    public function testPostSendsJsonBodyAndAuthHeaders(): void
    {
        $this->http->push(new Response(200, ['Content-Type' => 'application/json'], '{"status":"ok"}'));

        $result = $this->client()->request('POST', '/v2/articles', ['tickers' => ['AAPL'], 'pageSize' => 5]);

        self::assertSame(['status' => 'ok'], $result);

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.finlight.me/v2/articles', (string) $request->getUri());
        self::assertSame('test-key', $request->getHeaderLine('X-API-KEY'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('finlight-php/', $request->getHeaderLine('User-Agent'));
        self::assertSame('{"tickers":["AAPL"],"pageSize":5}', (string) $request->getBody());
    }

    public function testGetEncodesBooleansAsLiteralTrueAndFalse(): void
    {
        $this->http->push(new Response(200, [], '{"link":"https://example.com"}'));

        $this->client()->request('GET', '/v2/articles/by-link', [
            'link' => 'https://example.com/a b',
            'includeContent' => true,
            'includeEntities' => false,
        ]);

        $query = $this->http->lastRequest()->getUri()->getQuery();

        self::assertStringContainsString('includeContent=true', $query);
        self::assertStringContainsString('includeEntities=false', $query);

        parse_str($query, $parsed);
        self::assertSame('https://example.com/a b', $parsed['link'] ?? null);
    }

    public function testRetriesRetryableStatusThenSucceeds(): void
    {
        $this->http
            ->push(new Response(503, [], 'unavailable'))
            ->push(new Response(200, [], '{"status":"ok"}'));

        $result = $this->client()->request('POST', '/v2/articles');

        self::assertSame(['status' => 'ok'], $result);
        self::assertSame(2, $this->http->requestCount());
    }

    public function testStopsAfterConfiguredNumberOfAttempts(): void
    {
        $this->http
            ->push(new Response(500, [], 'boom'))
            ->push(new Response(500, [], 'boom'))
            ->push(new Response(500, [], 'boom'));

        try {
            $this->client()->request('POST', '/v2/articles');
            self::fail('Expected an ApiException.');
        } catch (ApiException $error) {
            self::assertSame(500, $error->statusCode);
        }

        self::assertSame(3, $this->http->requestCount());
    }

    public function testDoesNotRetryNonRetryableStatus(): void
    {
        $this->http->push(new Response(400, [], '{"message":"pageSize must be at most 100"}'));

        try {
            $this->client()->request('POST', '/v2/articles');
            self::fail('Expected an ApiException.');
        } catch (ApiException $error) {
            self::assertSame(400, $error->statusCode);
            self::assertSame('finlight: pageSize must be at most 100', $error->getMessage());
            self::assertSame('{"message":"pageSize must be at most 100"}', $error->responseBody);
        }

        self::assertSame(1, $this->http->requestCount());
    }

    public function testMapsUnauthorizedToAuthenticationException(): void
    {
        $this->http->push(new Response(401, [], '{"message":"Invalid API key"}'));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('finlight: Invalid API key');

        $this->client()->request('GET', '/v2/sources');
    }

    public function testMapsNotFound(): void
    {
        $this->http->push(new Response(404, [], ''));

        $this->expectException(NotFoundException::class);

        $this->client()->request('GET', '/v2/articles/by-link', ['link' => 'https://example.com']);
    }

    public function testMapsRateLimitAndReadsRetryAfterHeader(): void
    {
        $this->http->push(new Response(429, ['Retry-After' => '30'], '{"message":"Too many requests"}'));

        try {
            // retryCount 1 disables retries so the 429 surfaces directly.
            $this->client(retryCount: 1)->request('GET', '/v2/sources');
            self::fail('Expected a RateLimitException.');
        } catch (RateLimitException $error) {
            self::assertSame(429, $error->statusCode);
            self::assertSame(30, $error->retryAfterSeconds);
        }
    }

    public function testWrapsTransportFailures(): void
    {
        $this->http->push(new FakeClientException('connection refused'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('connection refused');

        $this->client()->request('GET', '/v2/sources');
    }

    public function testDoesNotRetryTransportFailures(): void
    {
        $this->http->push(new FakeClientException('connection refused'));

        try {
            $this->client()->request('GET', '/v2/sources');
        } catch (TransportException) {
            // expected
        }

        self::assertSame(1, $this->http->requestCount());
    }

    public function testRetriesRateLimitedResponses(): void
    {
        $this->http
            ->push(new Response(429, [], 'slow down'))
            ->push(new Response(200, [], '{"status":"ok"}'));

        $result = $this->client()->request('GET', '/v2/sources');

        self::assertSame(['status' => 'ok'], $result);
        self::assertSame(2, $this->http->requestCount());
    }

    public function testReadsRetryAfterGivenAsAnHttpDate(): void
    {
        $at = gmdate('D, d M Y H:i:s \G\M\T', time() + 120);
        $this->http->push(new Response(429, ['Retry-After' => $at], ''));

        try {
            $this->client(retryCount: 1)->request('GET', '/v2/sources');
            self::fail('Expected a RateLimitException.');
        } catch (RateLimitException $error) {
            self::assertNotNull($error->retryAfterSeconds);
            self::assertEqualsWithDelta(120, $error->retryAfterSeconds, 5);
        }
    }

    public function testBackoffDoublesThenStopsAtTheCeiling(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            $this->http->push(new Response(503, [], ''));
        }

        /** @var list<int> $sleeps */
        $sleeps = [];

        $factory = new Psr17Factory();
        $client = new ApiClient(
            new Config(apiKey: 'test-key', retryCount: 8),
            $this->http,
            $factory,
            $factory,
            static function (int $microseconds) use (&$sleeps): void {
                $sleeps[] = $microseconds;
            },
        );

        try {
            $client->request('GET', '/v2/sources');
        } catch (ApiException) {
            // expected after the final attempt
        }

        $milliseconds = array_map(static fn (int $us): int => intdiv($us, 1000), $sleeps);

        self::assertSame([500, 1_000, 2_000, 4_000, 8_000, 16_000, 30_000], $milliseconds);
        self::assertSame(8, $this->http->requestCount());
    }

    public function testTrailingSlashInBaseUrlDoesNotDoubleUp(): void
    {
        $this->http->push(new Response(200, [], '[]'));

        $factory = new Psr17Factory();
        $client = new ApiClient(
            new Config(apiKey: 'test-key', baseUrl: 'https://api.finlight.me/'),
            $this->http,
            $factory,
            $factory,
        );

        $client->request('GET', '/v2/sources');

        self::assertSame('https://api.finlight.me/v2/sources', (string) $this->http->lastRequest()->getUri());
    }

    public function testRejectsNonJsonSuccessBody(): void
    {
        $this->http->push(new Response(200, [], '<html>maintenance</html>'));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('could not decode the API response as JSON');

        $this->client()->request('GET', '/v2/sources');
    }

    private function client(int $retryCount = 3): ApiClient
    {
        $factory = new Psr17Factory();

        return new ApiClient(
            new Config(apiKey: 'test-key', retryCount: $retryCount),
            $this->http,
            $factory,
            $factory,
            static function (int $microseconds): void {
            },
        );
    }
}
