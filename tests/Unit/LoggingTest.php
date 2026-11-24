<?php

declare(strict_types=1);

namespace Finlight\Tests\Unit;

use Finlight\FinlightClient;
use Finlight\FinlightConfig;
use Finlight\FinlightException;
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
        $t->http->push(new ConnectException('Connection refused', new Request('GET', '/')));
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
