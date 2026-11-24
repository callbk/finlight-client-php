<?php

declare(strict_types=1);

namespace Finlight\Tests\Unit;

use Finlight\FinlightClient;
use Finlight\FinlightConfig;
use Finlight\FinlightException;
use Finlight\FinlightNetworkException;
use Finlight\Tests\Support\MemoryLogger;
use Finlight\Tests\Support\MockHttpClient;
use Finlight\Tests\Support\TestClient;
use Finlight\Webhooks;
use GuzzleHttp\Exception\ConnectException;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * R-LOG-1: the key and secrets never show in logs, messages or dumps; no global handlers.
 */
final class LoggingTest extends TestCase
{
    private const KEY = 'sk_secret_test_key_that_must_never_leak_0123456789';

    public function testTheKeyNeverShows(): void
    {
        $logger = new MemoryLogger();
        $t = new TestClient(['apiKey' => self::KEY], $logger);
        $texts = [];
        // The client's error holds the request, and that request carries the key.
        $t->http->push(new ConnectException('Connection refused', new Request('GET', '/', ['X-API-KEY' => self::KEY])));
        $t->http->push(MockHttpClient::json(429, ['code' => 'RATE_LIMITED', 'type' => 't', 'title' => 't', 'detail' => 'Exceeded rate limit', 'limitType' => 'burst']));
        $t->http->push(MockHttpClient::json(500, 'gateway down'));
        $t->http->always(static fn () => MockHttpClient::json(401, ['type' => 't', 'title' => 'Unauthorized', 'status' => 401, 'code' => 'UNAUTHORIZED', 'detail' => 'Invalid API key']));
        try {
            $t->client->articles->search(['query' => 'x']);
        } catch (FinlightException $e) {
            $texts[] = $e->getMessage();
            $texts[] = (string) $e;
            $texts[] = $e->getTraceAsString();
        }
        $texts[] = $logger->text();
        $texts[] = print_r($t->config, true);
        ob_start();
        var_dump($t->config);
        $texts[] = (string) ob_get_clean();
        $texts[] = print_r($t->client, true);
        // var_export() and an (array) cast see every property; the injected mock above records its
        // requests, so these use a config of their own.
        $config = new FinlightConfig(self::KEY, logger: $logger);
        $texts[] = var_export($config, true);
        $texts[] = print_r((array) $config, true);
        $texts[] = json_encode($config, \JSON_THROW_ON_ERROR);
        $texts[] = json_encode($t->config, \JSON_THROW_ON_ERROR);
        self::assertSame(self::KEY, $config->apiKey, 'the key can still be read back');
        self::assertTrue(isset($config->apiKey));
        self::assertSame(self::KEY, (clone $config)->apiKey, 'a clone keeps the key');
        self::assertNotEmpty($logger->lines, 'the debug and warning lines were written');

        try {
            Webhooks::verifySignature('{}', 'sha256=00', '2026-11-23T21:04:05.123Z', self::KEY);
        } catch (FinlightException $e) {
            $texts[] = (string) $e;
        }
        foreach ($texts as $text) {
            self::assertStringNotContainsString(self::KEY, $text);
        }
    }

    public function testANetworkErrorDumpsWithoutTheRequest(): void
    {
        $t = new TestClient(['apiKey' => self::KEY, 'maxRetries' => 0]);
        $cause = new ConnectException('Connection refused', new Request('GET', '/', ['X-API-KEY' => self::KEY]));
        $t->http->push($cause);
        try {
            $t->client->articles->search(['query' => 'x']);
            self::fail('no error');
        } catch (FinlightNetworkException $e) {
            self::assertSame($cause, $e->getPrevious(), 'the original error stays reachable');
            ob_start();
            var_dump($e);
            $dumps = [(string) ob_get_clean(), print_r($e, true), (string) $e, $e->getMessage()];
            foreach ($dumps as $text) {
                self::assertStringNotContainsString(self::KEY, $text);
            }
            self::assertStringContainsString(ConnectException::class, $dumps[1]);
        }
    }

    public function testARealGuzzleErrorDumpsWithoutTheKey(): void
    {
        $client = new FinlightClient(new FinlightConfig(self::KEY, baseUrl: 'http://127.0.0.1:1', maxRetries: 0, timeout: 5.0));
        try {
            $client->articles->search(['query' => 'x']);
            self::fail('no error');
        } catch (FinlightNetworkException $e) {
            ob_start();
            var_dump($e);
            self::assertStringNotContainsString(self::KEY, (string) ob_get_clean());
            self::assertStringNotContainsString(self::KEY, print_r($e, true));
            self::assertNotNull($e->getPrevious());
        }
    }

    public function testTheConfigKeyIsReadOnly(): void
    {
        $config = new FinlightConfig(self::KEY);
        $this->expectException(\Error::class);
        $config->apiKey = 'other'; // @phpstan-ignore assign.propertyReadOnly
    }

    public function testCreatingAClientInstallsNothingGlobal(): void
    {
        $errorBefore = set_error_handler(null);
        restore_error_handler();
        $exceptionBefore = set_exception_handler(null);
        restore_exception_handler();
        ob_start();
        new FinlightClient(new FinlightConfig(self::KEY));
        new FinlightClient(self::KEY);
        self::assertSame('', ob_get_clean(), 'nothing on stdout');
        $errorAfter = set_error_handler(null);
        restore_error_handler();
        $exceptionAfter = set_exception_handler(null);
        restore_exception_handler();
        self::assertSame($errorBefore, $errorAfter);
        self::assertSame($exceptionBefore, $exceptionAfter);
    }
}
