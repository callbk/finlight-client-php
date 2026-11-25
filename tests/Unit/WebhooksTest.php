<?php

declare(strict_types=1);

namespace Finlight\Tests\Unit;

use Finlight\FinlightDecodeException;
use Finlight\Tests\Support\FakeClock;
use Finlight\WebhookEvent;
use Finlight\Webhooks;
use Finlight\WebhookVerificationException;
use Finlight\WrongPayloadVersion;
use PHPUnit\Framework\TestCase;

/**
 * R-HOOK-1 .. R-HOOK-3 beyond the kit vectors.
 */
final class WebhooksTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const TS = '2026-11-23T21:04:05.123Z';

    private static function sign(string $body, string $ts = self::TS): string
    {
        return 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, self::SECRET);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TS);
    }

    private static function body(mixed $version, bool $withVersion = true): string
    {
        $body = ['eventId' => 'evt_x', 'event' => 'article.created', 'occurredAt' => self::TS];
        if ($withVersion) {
            $body['payloadVersion'] = $version;
        }
        $body['data'] = ['id' => 'a', 'link' => 'https://x.example/ü', 'source' => ['domain' => 'x.example'], 'title' => 'Ünïcode'];

        return json_encode($body, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
    }

    private static function construct(string $body, ?string $signature = null): WebhookEvent|WrongPayloadVersion
    {
        return Webhooks::constructEventFromValues($body, $signature ?? self::sign($body), self::TS, self::SECRET, now: self::now());
    }

    public function testPayloadVersionFollowsTheServerRule(): void
    {
        $event = self::construct(self::body(3.0));
        self::assertInstanceOf(WebhookEvent::class, $event, '3.0 is v3');

        $wrong = self::construct(self::body('3'));
        self::assertInstanceOf(WrongPayloadVersion::class, $wrong);
        self::assertSame('3', $wrong->payloadVersion);
        self::assertTrue($wrong->payloadVersionPresent);
        self::assertSame(2, $wrong->detectedVersion);
        self::assertSame('https://x.example/ü', $wrong->link);
        self::assertSame($wrong->raw, $wrong->rawJson());

        foreach ([true, 4, 2, null] as $version) {
            self::assertInstanceOf(WrongPayloadVersion::class, self::construct(self::body($version)));
        }
        self::assertNotContains(\Throwable::class, (array) class_implements($wrong), 'WrongPayloadVersion is a result, not an error');
    }

    public function testATamperedV2BodyIsASignatureErrorFirst(): void
    {
        $body = self::body(null, false);
        $this->expectException(WebhookVerificationException::class);
        try {
            self::construct($body . ' ', self::sign($body));
        } catch (WebhookVerificationException $e) {
            self::assertSame('signature_mismatch', $e->reason());
            throw $e;
        }
    }

    public function testASignedBodyThatIsNotAnObjectIsADecodeError(): void
    {
        foreach (['not json', '[1,2]', '[]', '"text"'] as $body) {
            try {
                self::construct($body);
                self::fail('accepted ' . $body);
            } catch (FinlightDecodeException $e) {
                self::assertSame('decode', $e->kind());
            }
        }
    }

    public function testInputsThatCanNeverVerify(): void
    {
        $body = self::body(3);
        $cases = [
            'invalid_raw_body' => static fn () => Webhooks::constructEvent(json_decode($body, true), [], self::SECRET), // @phpstan-ignore argument.type
            'invalid_secret' => static fn () => Webhooks::verifySignature($body, self::sign($body), self::TS, ''),
            'duplicate_header' => static fn () => Webhooks::constructEvent($body, ['X-Webhook-Signature' => [self::sign($body), self::sign($body)], 'X-Webhook-Timestamp' => self::TS], self::SECRET, now: self::now()),
            'non_ascii_header' => static fn () => Webhooks::constructEventFromValues($body, self::sign($body), '2026-11-23T21:04:05.123Z ü', self::SECRET, now: self::now()),
        ];
        foreach ($cases as $reason => $call) {
            try {
                $call();
                self::fail('accepted: ' . $reason);
            } catch (WebhookVerificationException $e) {
                self::assertSame($reason, $e->reason());
            }
        }
        // The same header under two spellings is a duplicate too.
        try {
            Webhooks::constructEvent($body, ['X-Webhook-Signature' => self::sign($body), 'x-webhook-signature' => self::sign($body), 'X-Webhook-Timestamp' => self::TS], self::SECRET, now: self::now());
            self::fail('accepted a duplicate');
        } catch (WebhookVerificationException $e) {
            self::assertSame('duplicate_header', $e->reason());
        }
    }

    public function testToleranceAndClockSeams(): void
    {
        $body = self::body(3);
        $sig = self::sign($body);
        $clock = new FakeClock((float) self::now()->format('U.u') + 299);
        self::assertInstanceOf(WebhookEvent::class, Webhooks::constructEventFromValues($body, $sig, self::TS, self::SECRET, now: $clock));
        $clock->advance(2);
        try {
            Webhooks::constructEventFromValues($body, $sig, self::TS, self::SECRET, now: $clock);
            self::fail('accepted an old timestamp');
        } catch (WebhookVerificationException $e) {
            self::assertSame('timestamp_outside_tolerance', $e->reason());
        }
        self::assertInstanceOf(WebhookEvent::class, Webhooks::constructEventFromValues($body, $sig, self::TS, self::SECRET, 600.0, static fn () => self::now()->modify('+500 seconds')));
        self::assertSame(300.0, Webhooks::DEFAULT_TOLERANCE);
    }

    public function testEventFields(): void
    {
        $event = self::construct(self::body(3));
        self::assertInstanceOf(WebhookEvent::class, $event);
        self::assertNull($event->changedFields);
        self::assertFalse($event->isTest());
        self::assertSame('Ünïcode', $event->data->title);
        self::assertSame(self::TS, $event->occurredAt);
        self::assertArrayNotHasKey('changedFields', $event->toWire());
    }
}
