<?php

/**
 * This SDK has no WebSocket transport. The example builds the subscribe message and the dial
 * headers, then decodes frames the way your own socket loop would (here: sample frames).
 *
 *     FINLIGHT_API_KEY=... php examples/stream.php
 */

declare(strict_types=1);

require __DIR__ . '/_client.php';

use Finlight\FinlightApiException;
use Finlight\Frame;
use Finlight\Subscribe;

$config = example_config();

// 1. Dial rtrim(wssUrl, '/') (raw streams: + '/raw') with these headers, never with ?apiKey=.
$url = rtrim($config->wssUrl, '/');
$headers = Subscribe::dialHeaders($config->apiKey);
printf("dial %s with headers: %s\n", $url, implode(', ', array_keys($headers)));

// 2. Send the subscribe message. Send the same message again after every reconnect.
$subscribe = Subscribe::enriched([
    'query' => 'ticker:NVDA',
    'include' => ['companies'],
    'includeUpdates' => true,
]);
printf("subscribe: %s\n", $subscribe);

// 3. Decode what the server sends. Ping {"action":"ping","t":<ms>} every 25 s yourself.
$received = [
    '{"action":"pong","t":1,"serverNow":1795467845123}',
    '{"event":"connection.admitted","payloadVersion":3,"data":{"leaseId":"l-1","serverNow":1795467845123,"clientNonce":"c-1"}}',
    '{"event":"article.created","payloadVersion":3,"data":{"id":"a1","link":"https://news.example.com/1","source":{"domain":"news.example.com","type":null},"title":"First","companies":[]}}',
    '{"event":"connection.draining","payloadVersion":3,"data":{}}',
    '{"event":"article.updated","payloadVersion":3,"data":{"id":"a1","link":"https://news.example.com/1","source":{"domain":"news.example.com","type":null},"title":"First, updated","companies":[]}}',
    '{"event":"connection.preempted","payloadVersion":3,"data":{"reason":"connection_limit","newLeaseId":"l-2","timestamp":1795467845999}}',
];

$items = 0;
$lastSeenAt = null;
foreach ($received as $text) {
    $frame = Frame::decode($text);
    switch ($frame->kind()) {
        case Frame::CREATED:
        case Frame::UPDATED:
            $article = $frame->event()?->data;
            if ($article === null) {
                break;
            }
            $lastSeenAt = $article->availableAt ?? $article->firstSeenAt ?? $lastSeenAt;
            printf("%s: %s\n", $frame->kind(), $article->title);
            if (++$items >= 5) {
                break 2;
            }
            break;
        case Frame::ADMITTED:
            printf("admitted, lease %s (this does not mean the subscribe was accepted)\n", $frame->admitted()?->leaseId);
            break;
        case Frame::PONG:
            print("pong: the connection is alive\n");
            break;
        case Frame::PREEMPTED:
            $info = $frame->preempted();
            if ($info?->reason === 'connection_limit') {
                printf("preempted: another connection took over (%s); do not reconnect\n", $info->newLeaseId);
                break 2;
            }
            print("preempted: quota used up; try again in an hour\n");
            break 2;
        case Frame::ERROR:
            $error = $frame->error();
            if ($error instanceof FinlightApiException && $error->code() === 'VALIDATION_FAILED') {
                printf("subscribe rejected: %s\n", $error->detail());
                break 2;
            }
            printf("server reported: %s\n", $error?->code() ?? 'error');
            break;
        default:
            // Frame::OTHER: an event this SDK does not know yet. Skip it.
            break;
    }
}
printf("%d items; after a reconnect, fill gaps from lastSeenAt %s with articles->searchAll()\n", $items, $lastSeenAt ?? '-');
