<?php

declare(strict_types=1);

namespace Finlight\Tests\Conformance;

use Finlight\Tests\Support\Kit;
use Finlight\WebhookEvent;
use Finlight\Webhooks;
use Finlight\WebhookVerificationException;
use Finlight\WrongPayloadVersion;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * W-01 .. W-16: webhook verification over the exact received bytes, in every header form PHP has.
 */
final class WebhooksTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function webhookCases(): array
    {
        return Kit::cases('fixtures/webhook');
    }

    #[DataProvider('webhookCases')]
    public function testWebhookCase(string $rel): void
    {
        $fx = Kit::load($rel);
        $request = Kit::obj($fx['request']);
        $body = base64_decode(Kit::str($request['rawBodyBase64']), true);
        self::assertIsString($body);
        $headers = [];
        foreach (Kit::obj($request['headers']) as $name => $value) {
            $headers[$name] = Kit::str($value);
        }
        $verify = Kit::obj($fx['verify']);
        $now = new \DateTimeImmutable(Kit::str($verify['now']));
        $tolerance = $verify['toleranceSeconds'];
        self::assertIsInt($tolerance);
        $secret = Kit::str($fx['secret']);
        $expect = Kit::obj($fx['expect']);

        // Every header form gives the same outcome: an array, $_SERVER, a PSR-7 request, the values.
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $psr = new ServerRequest('POST', 'https://hooks.example.com/finlight', $headers, $body);
        $find = static function (string $wanted) use ($headers): ?string {
            foreach ($headers as $name => $value) {
                if (strtolower($name) === strtolower($wanted)) {
                    return $value;
                }
            }

            return null;
        };
        $calls = [
            'array' => static fn () => Webhooks::constructEvent($body, $headers, $secret, (float) $tolerance, $now),
            'server' => static fn () => Webhooks::constructEvent($body, $server, $secret, (float) $tolerance, $now),
            'psr7' => static fn () => Webhooks::constructEvent($psr->getBody(), $psr, $secret, (float) $tolerance, $now),
            'values' => static fn () => Webhooks::constructEventFromValues($body, $find('X-Webhook-Signature'), $find('X-Webhook-Timestamp'), $secret, (float) $tolerance, $now),
        ];

        foreach ($calls as $form => $call) {
            switch ($expect['outcome']) {
                case 'event':
                    $event = $call();
                    self::assertInstanceOf(WebhookEvent::class, $event, $form);
                    $this->checkEvent($fx, $event);
                    break;
                case 'wrongPayloadVersion':
                    $result = $call();
                    self::assertInstanceOf(WrongPayloadVersion::class, $result, $form);
                    self::assertSame($expect['detectedVersion'], $result->detectedVersion);
                    self::assertSame($expect['payloadVersionPresent'], $result->payloadVersionPresent);
                    self::assertNull($result->payloadVersion);
                    self::assertSame($expect['link'], $result->link);
                    self::assertSame(json_decode($body, true), $result->raw);
                    break;
                case 'error':
                    try {
                        $call();
                        self::fail('verification passed for ' . $form);
                    } catch (WebhookVerificationException $e) {
                        self::assertSame($expect['reason'], $e->reason(), $form);
                        self::assertSame('webhook_verification', $e->kind());
                        self::assertStringNotContainsString($secret, $e->getMessage());
                    }
                    break;
                default:
                    self::fail('unknown outcome');
            }
        }

        // verifySignature alone agrees with signatureValid where the timestamp is in range.
        if ($expect['outcome'] !== 'error') {
            Webhooks::verifySignature($body, $find('X-Webhook-Signature'), $find('X-Webhook-Timestamp'), $secret, (float) $tolerance, $now);
        }
    }

    /**
     * @param array<string, mixed> $fx
     */
    private function checkEvent(array $fx, WebhookEvent $event): void
    {
        $want = Kit::obj(Kit::obj($fx['expect'])['event']);
        self::assertSame($want['eventId'], $event->eventId);
        self::assertSame($want['event'], $event->event);
        self::assertSame($want['occurredAt'], $event->occurredAt);
        self::assertSame($want['subscriptionId'], $event->subscriptionId);
        self::assertSame($want['isTest'], $event->isTest());
        self::assertSame($want['link'], $event->data->link);
        self::assertSame($want['changedFields'] ?? null, $event->changedFields);
        self::assertSame(3, $event->payloadVersion);
        self::assertNotNull($event->occurredAtTime());

        switch ($fx['id']) {
            case 'W-03':
                self::assertFalse($event->data->contentPresent());
                break;
            case 'W-04':
                self::assertFalse($event->data->contentPresent());
                self::assertFalse($event->data->sentimentPresent());
                self::assertFalse($event->data->companiesPresent());
                self::assertFalse($event->data->eventsPresent());
                self::assertNull($event->data->availableAt);
                self::assertSame($event->data->firstSeenAt, $event->occurredAt);
                break;
        }
    }
}
