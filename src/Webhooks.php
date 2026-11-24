<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Json;
use Finlight\Internal\Time;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Verify and read finlight webhooks on your own server. All methods are static.
 *
 * Always pass the raw request body exactly as received (file_get_contents('php://input'),
 * $request->getContent(), (string) $request->getBody()). Parsed and re-encoded JSON never matches
 * the signature.
 */
final class Webhooks
{
    /** Default tolerance for the timestamp, in seconds, in both directions. */
    public const DEFAULT_TOLERANCE = 300.0;

    public const SIGNATURE_HEADER = 'X-Webhook-Signature';
    public const TIMESTAMP_HEADER = 'X-Webhook-Timestamp';

    private function __construct()
    {
    }

    /**
     * Verify a webhook request and read its body.
     *
     * $headers can be a PSR-7 request, an array of name => value or name => list of values
     * (getallheaders(), Laravel/Symfony $request->headers->all()), $_SERVER (HTTP_X_WEBHOOK_...),
     * or any iterable of the same. Names are matched without regard to case.
     *
     * Returns a WebhookEvent, or WrongPayloadVersion when the signed body is not payloadVersion 3
     * (answer that with 2xx too). Throws WebhookVerificationException when verification fails and
     * FinlightDecodeException when a correctly signed body cannot be read.
     *
     * @param string|StreamInterface                                    $rawBody
     * @param MessageInterface|iterable<array-key, mixed>               $headers
     * @param \DateTimeInterface|ClockInterface|(\Closure(): \DateTimeInterface)|null $now
     */
    public static function constructEvent(
        mixed $rawBody,
        mixed $headers,
        #[\SensitiveParameter] string $secret,
        float $tolerance = self::DEFAULT_TOLERANCE,
        \DateTimeInterface|ClockInterface|\Closure|null $now = null,
    ): WebhookEvent|WrongPayloadVersion {
        $body = self::body($rawBody);
        $signature = self::header($headers, self::SIGNATURE_HEADER);
        $timestamp = self::header($headers, self::TIMESTAMP_HEADER);

        return self::constructEventFromValues($body, $signature, $timestamp, $secret, $tolerance, $now);
    }

    /**
     * Like constructEvent(), with the values of the X-Webhook-Signature and X-Webhook-Timestamp
     * headers passed as strings.
     *
     * @param string|StreamInterface                                    $rawBody
     * @param \DateTimeInterface|ClockInterface|(\Closure(): \DateTimeInterface)|null $now
     */
    public static function constructEventFromValues(
        mixed $rawBody,
        ?string $signature,
        ?string $timestamp,
        #[\SensitiveParameter] string $secret,
        float $tolerance = self::DEFAULT_TOLERANCE,
        \DateTimeInterface|ClockInterface|\Closure|null $now = null,
    ): WebhookEvent|WrongPayloadVersion {
        $body = self::body($rawBody);
        self::verifySignature($body, $signature, $timestamp, $secret, $tolerance, $now);

        try {
            $parsed = Json::decode($body);
        } catch (\JsonException $e) {
            throw new FinlightDecodeException('finlight: the webhook body is not valid JSON', null, null, $e);
        }
        if (!Json::isObject($parsed) || !Json::looksLikeObject($body)) {
            throw new FinlightDecodeException('finlight: the webhook body is not a JSON object', null, $parsed);
        }
        $present = array_key_exists('payloadVersion', $parsed);
        $version = $present ? $parsed['payloadVersion'] : null;
        if (!self::isV3($version)) {
            return new WrongPayloadVersion(2, $version, $present, $parsed, self::linkOf($parsed));
        }

        return WebhookEvent::fromWire($parsed);
    }

    /**
     * Check signature and timestamp. Returns nothing when valid; throws WebhookVerificationException
     * otherwise.
     *
     * The HMAC-SHA256 runs over "<timestamp>.<raw body>" with the UTF-8 bytes of the secret as key
     * (not hex-decoded) and is compared in constant time with the hex value after "sha256=". The
     * timestamp must be RFC 3339 and within $tolerance seconds of $now in both directions.
     *
     * @param string|StreamInterface                                    $rawBody
     * @param \DateTimeInterface|ClockInterface|(\Closure(): \DateTimeInterface)|null $now
     */
    public static function verifySignature(
        mixed $rawBody,
        ?string $signature,
        ?string $timestamp,
        #[\SensitiveParameter] string $secret,
        float $tolerance = self::DEFAULT_TOLERANCE,
        \DateTimeInterface|ClockInterface|\Closure|null $now = null,
    ): void {
        $body = self::body($rawBody);
        if ($secret === '') {
            throw self::fail(WebhookVerificationException::INVALID_SECRET, 'the webhook secret is empty');
        }
        if ($signature === null || $signature === '') {
            throw self::fail(WebhookVerificationException::MISSING_SIGNATURE, 'no X-Webhook-Signature header');
        }
        if ($timestamp === null || $timestamp === '') {
            throw self::fail(WebhookVerificationException::MISSING_TIMESTAMP, 'no X-Webhook-Timestamp header');
        }
        foreach ([self::SIGNATURE_HEADER => $signature, self::TIMESTAMP_HEADER => $timestamp] as $name => $value) {
            if (preg_match('/^[\x20-\x7E]*$/D', $value) !== 1) {
                throw self::fail(WebhookVerificationException::NON_ASCII_HEADER, sprintf('header %s contains characters outside printable ASCII', $name));
            }
        }
        if (!str_starts_with($signature, 'sha256=')) {
            throw self::fail(WebhookVerificationException::UNSUPPORTED_SIGNATURE_SCHEME, 'the signature must start with sha256=');
        }
        $sent = Time::parse($timestamp);
        if ($sent === null) {
            throw self::fail(WebhookVerificationException::UNPARSEABLE_TIMESTAMP, 'X-Webhook-Timestamp is not an RFC 3339 timestamp');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $given = substr($signature, strlen('sha256='));
        if (strlen($given) !== strlen($expected) || !hash_equals($expected, $given)) {
            throw self::fail(WebhookVerificationException::SIGNATURE_MISMATCH, 'the signature does not match');
        }
        $current = self::resolveNow($now);
        $delta = abs((float) $current->format('U.u') - (float) $sent->format('U.u'));
        if ($delta > $tolerance) {
            throw self::fail(WebhookVerificationException::TIMESTAMP_OUTSIDE_TOLERANCE, 'the timestamp is outside the tolerance');
        }
    }

    private static function body(mixed $rawBody): string
    {
        if (is_string($rawBody)) {
            return $rawBody;
        }
        if ($rawBody instanceof StreamInterface) {
            if ($rawBody->isSeekable()) {
                $rawBody->rewind();
            }

            return $rawBody->getContents();
        }
        throw self::fail(
            WebhookVerificationException::INVALID_RAW_BODY,
            'pass the raw request body as a string, exactly as received; parsed and re-encoded JSON never matches the signature',
        );
    }

    /**
     * Find one header value. Names are matched without regard to case, also in the CGI form
     * HTTP_X_WEBHOOK_TIMESTAMP. A header sent more than once fails.
     */
    private static function header(mixed $headers, string $wanted): ?string
    {
        $want = strtolower($wanted);
        $found = [];
        if ($headers instanceof MessageInterface) {
            foreach ($headers->getHeaders() as $name => $values) {
                if (self::normalize((string) $name) === $want) {
                    foreach ($values as $value) {
                        $found[] = $value;
                    }
                }
            }
        } elseif (is_iterable($headers)) {
            foreach ($headers as $name => $value) {
                if (!is_string($name) || self::normalize($name) !== $want) {
                    continue;
                }
                if (is_array($value)) {
                    foreach ($value as $item) {
                        $found[] = $item;
                    }
                } else {
                    $found[] = $value;
                }
            }
        } elseif ($headers !== null) {
            throw new FinlightValidationException('finlight: headers must be a PSR-7 message, an array or an iterable of name => value', 'headers');
        }
        if ($found === []) {
            return null;
        }
        if (count($found) > 1) {
            throw self::fail(WebhookVerificationException::DUPLICATE_HEADER, sprintf('header %s was sent more than once', $wanted));
        }
        $value = $found[0];
        if (!is_string($value)) {
            throw self::fail(WebhookVerificationException::NON_ASCII_HEADER, sprintf('header %s is not text', $wanted));
        }

        return $value;
    }

    private static function normalize(string $name): string
    {
        $lower = strtolower($name);
        if (str_starts_with($lower, 'http_')) {
            $lower = str_replace('_', '-', substr($lower, 5));
        }

        return $lower;
    }

    /**
     * @param \DateTimeInterface|ClockInterface|(\Closure(): \DateTimeInterface)|null $now
     */
    private static function resolveNow(\DateTimeInterface|ClockInterface|\Closure|null $now): \DateTimeInterface
    {
        if ($now instanceof \DateTimeInterface) {
            return $now;
        }
        if ($now instanceof ClockInterface) {
            return $now->now();
        }
        if ($now instanceof \Closure) {
            $value = $now();
            // @phpstan-ignore instanceof.alwaysTrue (a caller's closure may return anything)
            if (!$value instanceof \DateTimeInterface) {
                throw new FinlightValidationException('finlight: now must return a DateTimeInterface', 'now');
            }

            return $value;
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Same rule as the server (v === 3): a JSON number equal to 3; never a bool or a string.
     * 3.0 counts as 3.
     */
    private static function isV3(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && $value == 3;
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private static function linkOf(array $parsed): ?string
    {
        if (is_string($parsed['link'] ?? null)) {
            return $parsed['link'];
        }
        $data = $parsed['data'] ?? null;
        if (is_array($data) && is_string($data['link'] ?? null)) {
            return $data['link'];
        }

        return null;
    }

    private static function fail(string $reason, string $message): WebhookVerificationException
    {
        return new WebhookVerificationException('finlight: webhook verification failed: ' . $message, $reason);
    }
}
