<?php

declare(strict_types=1);

namespace Finlight\Tests\Conformance;

use Finlight\FinlightClient;
use Finlight\Subscribe;
use Finlight\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

/**
 * S-02 (R-WS-3): the API key goes out as a header named exactly "x-api-key", never in the URL.
 *
 * A plain TCP listener on 127.0.0.1 reads the raw bytes of an upgrade request written with
 * Subscribe::dialHeaders(), the way a customer's socket loop sends them. Nothing rewrites the
 * header names on the way, so the test sees the bytes as they leave.
 */
final class WsRawBytesTest extends TestCase
{
    public function testLowercaseApiKeyOnTheWire(): void
    {
        $fx = Kit::load('ws-sessions/v2-pong-before-subscribe.json');
        $dial = Kit::obj(Kit::list($fx['steps'])[0]);
        $key = Kit::str(Kit::obj($dial['expectRequestHeaders'])['x-api-key']);

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, (string) $errstr);
        $name = stream_socket_get_name($server, false);
        self::assertIsString($name);
        $client = stream_socket_client('tcp://' . $name, $errno, $errstr, 2.0);
        self::assertIsResource($client, (string) $errstr);

        $lines = ['GET / HTTP/1.1', 'Host: wss.finlight.me', 'Upgrade: websocket', 'Connection: Upgrade', 'Sec-WebSocket-Version: 13', 'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16))];
        foreach (Subscribe::dialHeaders($key) as $header => $value) {
            $lines[] = $header . ': ' . $value;
        }
        fwrite($client, implode("\r\n", $lines) . "\r\n\r\n");

        $conn = stream_socket_accept($server, 2.0);
        self::assertIsResource($conn);
        stream_set_timeout($conn, 2);
        $bytes = '';
        while (!str_contains($bytes, "\r\n\r\n")) {
            $chunk = fread($conn, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bytes .= $chunk;
        }
        fclose($conn);
        fclose($client);
        fclose($server);

        $requestLine = strstr($bytes, "\r\n", true);
        self::assertIsString($requestLine);
        self::assertStringNotContainsString('apiKey=', $requestLine, 'the key is never put into the URL');
        self::assertStringNotContainsString($key, $requestLine);
        self::assertStringContainsString("\r\nx-api-key: " . $key . "\r\n", $bytes, 'header name exactly "x-api-key"');
        self::assertStringNotContainsString('X-Api-Key', $bytes);
        self::assertStringNotContainsString('X-API-KEY', $bytes);
        self::assertStringContainsString("\r\nx-client-version: " . FinlightClient::VERSION . "\r\n", $bytes);
        self::assertStringContainsString("\r\nuser-agent: finlight-client-php/" . FinlightClient::VERSION . "\r\n", $bytes);
        self::assertStringNotContainsString('x-takeover', $bytes, 'takeover is off by default');
        self::assertSame('true', Subscribe::dialHeaders($key, takeover: true)['x-takeover']);
    }
}
