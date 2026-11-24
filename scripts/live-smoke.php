<?php

/**
 * Live smoke run against the finlight DEV API. Small load: a handful of REST calls and one short
 * WebSocket session (dial, subscribe, read until admitted plus the initial push, close).
 *
 * Needs FINLIGHT_API_KEY, FINLIGHT_BASE_URL and FINLIGHT_WSS_URL. There are no defaults, and
 * production hosts are refused. Run only with the owner's okay.
 *
 *     FINLIGHT_API_KEY=... FINLIGHT_BASE_URL=https://api.dev.finlight.me \
 *     FINLIGHT_WSS_URL=wss://wss.dev.finlight.me php scripts/live-smoke.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Finlight\FinlightApiException;
use Finlight\FinlightClient;
use Finlight\FinlightConfig;
use Finlight\ForbiddenException;
use Finlight\Frame;
use Finlight\NotFoundException;
use Finlight\Subscribe;

$env = [];
foreach (['FINLIGHT_API_KEY', 'FINLIGHT_BASE_URL', 'FINLIGHT_WSS_URL'] as $name) {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "skipped: {$name} is not set\n");
        exit(2);
    }
    $env[$name] = $value;
}
foreach ([$env['FINLIGHT_BASE_URL'], $env['FINLIGHT_WSS_URL']] as $url) {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!str_contains($host, '.dev.') && $host !== '127.0.0.1' && $host !== 'localhost') {
        fwrite(STDERR, "refused: {$host} is not a dev host\n");
        exit(2);
    }
}

$failures = 0;
$check = static function (string $name, callable $fn) use (&$failures): void {
    try {
        $note = $fn();
        printf("ok    %s%s\n", $name, is_string($note) && $note !== '' ? ': ' . $note : '');
    } catch (Throwable $e) {
        ++$failures;
        $extra = $e instanceof FinlightApiException ? sprintf(' [%s %s %s]', $e->status() ?? '-', $e->code() ?? '-', $e->requestId() ?? '-') : '';
        printf("FAIL  %s: %s%s\n", $name, get_class($e), $extra);
    }
};

$config = new FinlightConfig(apiKey: $env['FINLIGHT_API_KEY'], baseUrl: $env['FINLIGHT_BASE_URL'], wssUrl: $env['FINLIGHT_WSS_URL'], maxRetries: 1);
$client = new FinlightClient($config);
$state = [];

$check('sources.list', static function () use ($client): string {
    $sources = $client->sources->list();
    if (count($sources) === 0) {
        throw new RuntimeException('no sources');
    }

    return sprintf('%d sources, %d skipped', count($sources), count($sources->skipped()));
});
$check('articles.search', static function () use ($client, &$state): string {
    $page = $client->articles->search(['languages' => ['en'], 'limit' => 3, 'include' => ['companies', 'events']]);
    if ($page->articles === []) {
        throw new RuntimeException('no articles');
    }
    $state['article'] = $page->articles[0];
    foreach ($page->articles as $article) {
        if ($article->publishedAtTime() === null && $article->publishedAt !== null) {
            throw new RuntimeException('unreadable publishedAt');
        }
        foreach ($article->companies ?? [] as $company) {
            $state['companyId'] ??= $company->companyId;
        }
    }

    return sprintf('%d articles, hasMore %s, companies %s', count($page->articles), var_export($page->pagination->hasMore, true), $page->articles[0]->companiesPresent() ? 'present' : 'absent');
});
$check('articles.searchAll (2 pages of 2)', static function () use ($client): string {
    $result = $client->articles->searchAll(['languages' => ['en'], 'limit' => 2], maxPages: 2);
    $n = iterator_count($result->getIterator());

    return sprintf('%d articles, %d pages, truncated %s', $n, $result->pagesFetched(), var_export($result->truncated(), true));
});
$check('articles.getByLink + get', static function () use ($client, &$state): string {
    $article = $state['article'] ?? null;
    if (!$article instanceof Finlight\Article) {
        throw new RuntimeException('no article from search');
    }
    $byLink = $client->articles->getByLink($article->link, include: ['content']);
    $byId = $client->articles->get($article->id);
    if ($byLink->id !== $article->id || $byId->link !== $article->link) {
        throw new RuntimeException('lookup mismatch');
    }

    return 'content ' . ($byLink->contentPresent() ? 'present' : 'absent');
});
$check('articles.get unknown id -> NotFoundException', static function () use ($client): string {
    try {
        $client->articles->get('zzzzzzzzzzzzzzzzzzzzzz');
    } catch (NotFoundException $e) {
        return 'requestId ' . ($e->requestId() ?? '-');
    }
    throw new RuntimeException('no 404');
});
$check('companies.search + get', static function () use ($client, &$state): string {
    try {
        $companies = $client->companies->search('NVDA');
        $id = $state['companyId'] ?? null;
        foreach ($companies as $company) {
            $id ??= $company->companyId;
        }
        if (!is_int($id)) {
            return sprintf('%d results, nothing to get', count($companies));
        }
        $record = $client->companies->get($id);
        $again = $client->companies->get($id);

        return sprintf('%d results; get %d etag %s, revalidated %s', count($companies), $record->companyId, $record->cacheInfo()?->etag !== null ? 'yes' : 'no', var_export($again->cacheInfo()?->notModified, true));
    } catch (ForbiddenException) {
        return 'plan has no entityAccess (403 as expected)';
    }
});
$check('websocket: dial, subscribe, admitted', static function () use ($config): string {
    return ws_session($config);
});

printf("%s\n", $failures === 0 ? 'smoke passed' : "smoke FAILED: {$failures}");
exit($failures === 0 ? 0 : 1);

/**
 * One short WebSocket session with a minimal client (TLS socket, RFC 6455 framing).
 */
function ws_session(FinlightConfig $config): string
{
    $url = parse_url(rtrim($config->wssUrl, '/'));
    $host = (string) ($url['host'] ?? '');
    $secure = ($url['scheme'] ?? '') === 'wss';
    $port = (int) ($url['port'] ?? ($secure ? 443 : 80));
    $path = (string) ($url['path'] ?? '/') ?: '/';
    $socket = stream_socket_client(($secure ? 'tls://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, $config->connectTimeout);
    if ($socket === false) {
        throw new RuntimeException('dial failed: ' . $errstr);
    }
    stream_set_timeout($socket, 15);
    $lines = ["GET {$path} HTTP/1.1", "Host: {$host}", 'Upgrade: websocket', 'Connection: Upgrade', 'Sec-WebSocket-Version: 13', 'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16))];
    foreach (Subscribe::dialHeaders($config->apiKey) as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    fwrite($socket, implode("\r\n", $lines) . "\r\n\r\n");
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $chunk = fread($socket, 1);
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('no handshake answer');
        }
        $head .= $chunk;
    }
    $status = (int) (explode(' ', $head)[1] ?? 0);
    if ($status !== 101) {
        throw new RuntimeException('dial refused with ' . $status);
    }
    ws_send($socket, Subscribe::enriched(['query' => 'ticker:NVDA', 'language' => 'en', 'include' => ['companies']], 'php-smoke'));
    $kinds = [];
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        $text = ws_read($socket);
        if ($text === null) {
            break;
        }
        $frame = Frame::decode($text);
        $kinds[] = $frame->kind();
        if ($frame->kind() === Frame::ADMITTED && $frame->admitted()?->clientNonce !== 'php-smoke') {
            throw new RuntimeException('nonce not echoed');
        }
        if ($frame->kind() === Frame::ERROR || $frame->kind() === Frame::PREEMPTED) {
            break;
        }
        if ($frame->kind() === Frame::ADMITTED) {
            ws_send($socket, '{"action":"ping","t":' . (int) (microtime(true) * 1000) . '}');
        }
        if (in_array(Frame::PONG, $kinds, true) && in_array(Frame::CREATED, $kinds, true)) {
            break;
        }
    }
    ws_send($socket, pack('n', 1000), 0x8); // close with 1000
    fclose($socket);
    if (!in_array(Frame::ADMITTED, $kinds, true)) {
        throw new RuntimeException('no connection.admitted, got ' . implode(',', $kinds));
    }

    return 'frames: ' . implode(', ', $kinds);
}

/** @param resource $socket */
function ws_send($socket, string $text, int $opcode = 0x1): void
{
    $len = strlen($text);
    $head = chr(0x80 | $opcode);
    if ($len < 126) {
        $head .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $head .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $head .= chr(0x80 | 127) . pack('J', $len);
    }
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $len; ++$i) {
        $masked .= $text[$i] ^ $mask[$i % 4];
    }
    fwrite($socket, $head . $mask . $masked);
}

/** @param resource $socket */
function ws_read($socket): ?string
{
    $message = '';
    while (true) {
        $head = ws_bytes($socket, 2);
        if ($head === null) {
            return null;
        }
        $fin = (ord($head[0]) & 0x80) !== 0;
        $opcode = ord($head[0]) & 0x0F;
        $len = ord($head[1]) & 0x7F;
        if ($len === 126) {
            $len = ws_uint((string) ws_bytes($socket, 2), 'n');
        } elseif ($len === 127) {
            $len = ws_uint((string) ws_bytes($socket, 8), 'J');
        }
        $payload = $len > 0 ? (string) ws_bytes($socket, $len) : '';
        if ($opcode === 0x8) {
            return null;
        }
        if ($opcode === 0x9) { // protocol ping: answer with a pong
            ws_send($socket, $payload, 0xA);
            continue;
        }
        $message .= $payload;
        if ($fin && ($opcode === 0x1 || $opcode === 0x0)) {
            return $message;
        }
    }
}

function ws_uint(string $bytes, string $format): int
{
    $value = unpack($format, $bytes);

    return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
}

/** @param resource $socket */
function ws_bytes($socket, int $n): ?string
{
    $out = '';
    while (strlen($out) < $n) {
        $chunk = fread($socket, max(1, $n - strlen($out)));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $out .= $chunk;
    }

    return $out;
}
