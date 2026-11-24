<?php

declare(strict_types=1);

namespace Finlight\Tests\Conformance;

use Finlight\Article;
use Finlight\Frame;
use Finlight\Internal\Decode;
use Finlight\NotFoundException;
use Finlight\RateLimitedException;
use Finlight\FinlightApiException;
use Finlight\Tests\Support\Kit;
use Finlight\Tests\Support\MockHttpClient;
use Finlight\Tests\Support\TestClient;
use Finlight\WebhookEvent;
use Finlight\Webhooks;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * F-01 .. F-04: additions a v3 client must tolerate.
 */
final class ForwardCompatTest extends TestCase
{
    /** F-01 */
    public function testUnknownFieldsAtEveryLevel(): void
    {
        $fx = Kit::load('fixtures/forward-compat/unknown-fields.json');
        $article = Article::fromJson($fx['wire']);
        $known = Article::fromJson($fx['known']);
        self::assertSame($known->toWire(), $article->toWire());
        self::assertSame($fx['known'], $article->toWire());
        $raw = $article->rawJson();
        self::assertArrayHasKey('wordCount', $raw);
        self::assertArrayHasKey('story', $raw);
        self::assertArrayHasKey('score', Kit::obj($raw['sentiment']));
    }

    /** F-02 */
    public function testUnknownEnumValuesAreKept(): void
    {
        $fx = Kit::load('fixtures/forward-compat/unknown-enum-values.json');
        $article = Article::fromJson($fx['wire']);
        self::assertSame('wire_service', $article->source->type);
        self::assertSame('mixed', $article->sentiment?->label);
        self::assertSame('guarantor', ($article->companies ?? [])[0]->role);
        $event = ($article->events ?? [])[0];
        self::assertSame(['esg_controversy', 'mixed', 'extreme'], [$event->type, $event->direction, $event->importance]);
        self::assertSame('synthetic-new-category', $article->categories[1]);
        self::assertSame($fx['wire'], $article->toWire());
    }

    /** F-03 */
    public function testNewEnvelopeKeysAreIgnored(): void
    {
        $fx = Kit::load('fixtures/forward-compat/envelope-keys.json');
        foreach (Kit::list($fx['envelopes']) as $envelope) {
            $envelope = Kit::obj($envelope);
            $wire = Kit::obj($envelope['wire']);
            switch ($envelope['kind']) {
                case 'rest.search':
                    $page = Decode::articlePage($wire, new NullLogger());
                    self::assertCount(1, $page->articles);
                    self::assertFalse($page->pagination->hasMore);
                    self::assertSame([], $page->skipped);
                    break;
                case 'rest.article':
                    $t = new TestClient();
                    $t->http->push(MockHttpClient::json(200, $wire));
                    $article = $t->client->articles->get('x');
                    self::assertSame(Kit::obj($wire['article'])['id'], $article->id);
                    break;
                case 'webhook':
                    $event = WebhookEvent::fromWire($wire);
                    self::assertSame('article.created', $event->event);
                    self::assertSame(1, $event->rawJson()['deliveryAttempt']);
                    break;
                case 'ws.article':
                    $frame = Frame::decode(Kit::json($wire));
                    self::assertSame(Frame::CREATED, $frame->kind());
                    self::assertNotNull($frame->event()?->data);
                    self::assertSame(17, $frame->rawJson()['sequence']);
                    break;
                case 'ws.control':
                    self::assertSame(Frame::PONG, Frame::decode(Kit::json($wire))->kind());
                    break;
                default:
                    self::fail('unknown envelope kind ' . Kit::str($envelope['kind']));
            }
        }
    }

    /** F-04 */
    public function testUnknownEventsCodesAndLimitTypes(): void
    {
        $fx = Kit::load('fixtures/forward-compat/unknown-events-and-codes.json');
        foreach (Kit::list($fx['items']) as $item) {
            $item = Kit::obj($item);
            switch ($item['kind']) {
                case 'webhook.event':
                    $body = Kit::json($item['wire']);
                    $ts = '2026-11-23T21:04:05.123Z';
                    $secret = str_repeat('ab', 32);
                    $sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
                    $event = Webhooks::constructEventFromValues($body, $sig, $ts, $secret, now: new \DateTimeImmutable($ts));
                    self::assertInstanceOf(WebhookEvent::class, $event);
                    self::assertSame('article.retracted', $event->event);
                    break;
                case 'problem.code':
                case 'problem.limitType':
                    $t = new TestClient();
                    $status = $item['status'];
                    self::assertIsInt($status);
                    $t->http->always(static fn () => MockHttpClient::fromFixture(['status' => $status, 'headers' => $item['headers'], 'body' => $item['wire']]));
                    try {
                        $t->client->articles->search();
                        self::fail('no error');
                    } catch (FinlightApiException $e) {
                        $wire = Kit::obj($item['wire']);
                        self::assertSame($status, $e->status());
                        self::assertSame($wire['code'], $e->code());
                        self::assertSame($wire['detail'], $e->detail());
                        self::assertSame($wire['requestId'], $e->requestId());
                        self::assertCount(1, $t->http->requests, 'never retried');
                        if ($item['kind'] === 'problem.code') {
                            self::assertSame(FinlightApiException::class, $e::class);
                            self::assertSame('other', $e->apiKind());
                        } else {
                            self::assertInstanceOf(RateLimitedException::class, $e);
                            self::assertSame('rate_limited', $e->apiKind());
                            self::assertSame('daily', $e->limitType());
                        }
                        self::assertNotInstanceOf(NotFoundException::class, $e);
                    }
                    break;
                default:
                    self::fail('unknown item kind');
            }
        }
    }
}
