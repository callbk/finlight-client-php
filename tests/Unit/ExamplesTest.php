<?php

declare(strict_types=1);

namespace Finlight\Tests\Unit;

use Finlight\Tests\Support\Kit;
use Finlight\Tests\Support\PhpServer;
use PHPUnit\Framework\TestCase;

/**
 * R-TEST-1: the 5 standard examples load and run: search, search-all and lookup against a fake
 * API on 127.0.0.1, stream offline, and the webhook endpoint with signed test requests.
 */
final class ExamplesTest extends TestCase
{
    private const EXAMPLES = ['search', 'search-all', 'lookup', 'stream', 'webhook-endpoint'];

    public function testEveryExampleLints(): void
    {
        foreach (self::EXAMPLES as $name) {
            $path = Kit::root() . '/examples/' . $name . '.php';
            self::assertFileExists($path);
            exec(escapeshellarg(\PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
        }
    }

    public function testRestExamplesRunAgainstAFakeApi(): void
    {
        $server = new PhpServer(Kit::root() . '/tests/Support/fake-api.php');
        $env = ['FINLIGHT_API_KEY' => Kit::KEY, 'FINLIGHT_BASE_URL' => $server->url(), 'FINLIGHT_WSS_URL' => 'ws://127.0.0.1:1'];
        try {
            [$code, $out, $err] = PhpServer::run('examples/search.php', $env);
            self::assertSame(0, $code, $out . $err);
            self::assertStringContainsString('hasMore: yes', $out);
            self::assertStringContainsString('news.example.com', $out);

            [$code, $out, $err] = PhpServer::run('examples/search-all.php', $env);
            self::assertSame(0, $code, $out . $err);
            self::assertStringContainsString('5 articles on 3 pages', $out);

            [$code, $out, $err] = PhpServer::run('examples/lookup.php', $env);
            self::assertSame(0, $code, $out . $err);
            self::assertStringContainsString('by id:', $out);
            self::assertStringContainsString('not found:', $out);
            self::assertStringContainsString('ETag "', $out);
            self::assertMatchesRegularExpression('/\d+ sources/', $out);

            [$code, $out, $err] = PhpServer::run('examples/search.php', ['FINLIGHT_API_KEY' => ''] + $env);
            self::assertSame(2, $code, 'no key, no call');
        } finally {
            $server->stop();
        }
    }

    public function testStreamExampleBuildsAndDecodes(): void
    {
        [$code, $out, $err] = PhpServer::run('examples/stream.php', ['FINLIGHT_API_KEY' => Kit::KEY, 'FINLIGHT_WSS_URL' => 'wss://wss.dev.finlight.me']);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('dial wss://wss.dev.finlight.me with headers: x-api-key, x-client-version, user-agent', $out);
        self::assertStringContainsString('"payloadVersion":3', $out);
        self::assertStringContainsString('created: First', $out);
        self::assertStringContainsString('updated: First, updated', $out);
        self::assertStringContainsString('do not reconnect', $out);
        self::assertStringNotContainsString(Kit::KEY, $out);
    }

    public function testWebhookEndpointAnswersEveryCase(): void
    {
        $secret = str_repeat('5a', 32);
        $server = new PhpServer(Kit::root() . '/examples/webhook-endpoint.php', ['FINLIGHT_WEBHOOK_SECRET' => $secret]);
        try {
            $ts = gmdate('Y-m-d\TH:i:s') . '.000Z';
            $eventId = 'evt_example_' . bin2hex(random_bytes(6));
            $event = Kit::json(['eventId' => $eventId, 'event' => 'article.created', 'occurredAt' => $ts, 'payloadVersion' => 3, 'subscriptionId' => 's', 'data' => ['id' => 'a', 'link' => 'https://x.example/a', 'source' => ['domain' => 'x.example', 'type' => null], 'title' => 'Hello']]);
            $v2 = Kit::json(['link' => 'https://x.example/a', 'title' => 'Hello', 'isUpdate' => false]);
            $sign = static fn (string $body) => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);

            self::assertSame(204, $this->post($server, $event, $sign($event), $ts), 'event');
            self::assertSame(204, $this->post($server, $event, $sign($event), $ts), 'duplicate delivery');
            self::assertSame(204, $this->post($server, $v2, $sign($v2), $ts), 'WrongPayloadVersion is answered with 2xx');
            self::assertSame(400, $this->post($server, $event . ' ', $sign($event), $ts), 'tampered body');
            self::assertSame(400, $this->post($server, $event, null, $ts), 'no signature');
        } finally {
            $server->stop();
        }
    }

    private function post(PhpServer $server, string $body, ?string $signature, string $timestamp): int
    {
        $headers = "Content-Type: application/json\r\nX-Webhook-Timestamp: {$timestamp}\r\n";
        if ($signature !== null) {
            $headers .= "X-Webhook-Signature: {$signature}\r\n";
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => $headers, 'content' => $body, 'ignore_errors' => true, 'timeout' => 5]]);
        $stream = fopen($server->url() . '/', 'r', false, $context);
        self::assertIsResource($stream);
        $meta = stream_get_meta_data($stream);
        fclose($stream);
        $lines = $meta['wrapper_data'] ?? [];
        $status = is_array($lines) ? ($lines[0] ?? '') : '';
        self::assertIsString($status);
        if (preg_match('#^HTTP/\S+ (\d{3})#', $status, $m) !== 1) {
            self::fail('no status line: ' . $status);
        }

        return (int) $m[1];
    }
}
