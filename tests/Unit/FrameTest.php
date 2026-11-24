<?php

declare(strict_types=1);

namespace Finlight\Tests\Unit;

use Finlight\FinlightDecodeException;
use Finlight\Frame;
use Finlight\StreamEventKind;
use Finlight\ValidationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * R-WS-1: v3 frames first, v2 control frames as fallback, never a v2 article.
 */
final class FrameTest extends TestCase
{
    public function testV2ControlFramesMapToTheV3Kinds(): void
    {
        $admit = Frame::decode('{"action":"admit","leaseId":"l1","serverNow":5,"clientNonce":"n"}');
        self::assertSame(Frame::ADMITTED, $admit->kind());
        self::assertSame(['l1', 5, 'n'], [$admit->admitted()?->leaseId, $admit->admitted()?->serverNow, $admit->admitted()?->clientNonce]);

        self::assertSame(Frame::PONG, Frame::decode('{"action":"pong","t":1,"serverNow":2}')->kind());
        self::assertSame(Frame::PONG, Frame::decode('{"event":"connection.pong","payloadVersion":3,"data":{"t":null,"serverNow":2}}')->kind());

        $pre = Frame::decode('{"action":"preempted","reason":"connection_limit","newLeaseId":"x","timestamp":9}');
        self::assertSame(Frame::PREEMPTED, $pre->kind());
        self::assertSame(['connection_limit', 'x', 9], [$pre->preempted()?->reason, $pre->preempted()?->newLeaseId, $pre->preempted()?->timestamp]);

        foreach (['{"action":"error","error":"Message processing failed"}', '{"action":"error","data":"Message processing failed"}'] as $text) {
            $err = Frame::decode($text);
            self::assertSame(Frame::ERROR, $err->kind());
            $error = $err->error();
            self::assertNotNull($error);
            self::assertSame('Message processing failed', $error->detail());
            self::assertNull($error->code());
            self::assertSame('other', $error->apiKind());
        }
    }

    public function testAV2ArticleIsNeverAnArticle(): void
    {
        $frame = Frame::decode('{"action":"sendArticle","data":{"link":"https://x.example","title":"T"}}');
        self::assertSame(Frame::OTHER, $frame->kind());
        $event = $frame->event();
        self::assertNotNull($event);
        self::assertSame(StreamEventKind::OTHER, $event->kind);
        self::assertNull($event->data);
    }

    public function testUnknownEventsAreOtherNotErrors(): void
    {
        $frame = Frame::decode('{"event":"connection.draining","payloadVersion":3,"data":{"inMs":5}}');
        self::assertSame(Frame::OTHER, $frame->kind());
        $event = $frame->event();
        self::assertNotNull($event);
        self::assertSame('connection.draining', $event->event);
        self::assertSame(['inMs' => 5], $event->rawJson()['data']);
    }

    public function testValidationErrorsCarryFieldAndConstraints(): void
    {
        $frame = Frame::decode('{"event":"connection.error","payloadVersion":3,"data":{"code":"VALIDATION_FAILED","message":"Invalid subscription message","details":[{"field":"include","constraints":{"raw":"raw delivers the skeleton only"}}]}}');
        $error = $frame->error();
        self::assertInstanceOf(ValidationFailedException::class, $error);
        self::assertNull($error->status());
        self::assertSame('include', $error->errors()[0]->field);
        self::assertSame(['raw' => 'raw delivers the skeleton only'], $error->errors()[0]->constraints);
    }

    public function testUnreadableFramesThrow(): void
    {
        foreach (['', 'nope', '[1]', '{"event":"article.created","data":{"id":"x"}}'] as $text) {
            try {
                Frame::decode($text);
                self::fail('accepted ' . $text);
            } catch (FinlightDecodeException $e) {
                self::assertSame('decode', $e->kind());
            }
        }
    }
}
