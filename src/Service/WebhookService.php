<?php

declare(strict_types=1);

namespace Finlight\Service;

use Finlight\Exception\MalformedResponseException;
use Finlight\Exception\WebhookVerificationException;
use Finlight\Model\Article;

final class WebhookService
{
    private const SIGNATURE_PREFIX = 'sha256=';

    public const REPLAY_TOLERANCE_SECONDS = 300;

    /**
     * Verifies a webhook delivery and returns the article it carries.
     *
     * $rawBody must be the unparsed request body. Middleware that decodes and
     * re-encodes JSON before you read it will invalidate the signature.
     *
     * @param string      $signature The X-Webhook-Signature header, with or without the sha256= prefix.
     * @param string|null $timestamp The X-Webhook-Timestamp header. When present it is part of the
     *                               signed message and is checked against the replay window.
     *
     * @throws WebhookVerificationException
     */
    public static function constructEvent(
        string $rawBody,
        string $signature,
        string $endpointSecret,
        ?string $timestamp = null,
    ): Article {
        if ($endpointSecret === '') {
            throw new WebhookVerificationException('finlight: the webhook endpoint secret must not be empty.');
        }

        $hasTimestamp = $timestamp !== null && $timestamp !== '';

        self::verifySignature(
            $rawBody,
            self::normalizeSignature($signature),
            $endpointSecret,
            $hasTimestamp ? $timestamp : null
        );

        if ($hasTimestamp) {
            /** @var string $timestamp */
            self::verifyTimestamp($timestamp);
        }

        return self::parsePayload($rawBody);
    }

    private static function normalizeSignature(string $signature): string
    {
        $signature = trim($signature);

        if (str_starts_with($signature, self::SIGNATURE_PREFIX)) {
            $signature = substr($signature, strlen(self::SIGNATURE_PREFIX));
        }

        return $signature;
    }

    private static function verifySignature(
        string $payload,
        string $signature,
        string $secret,
        ?string $timestamp,
    ): void {
        $message = $timestamp === null ? $payload : $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $message, $secret);

        if (!hash_equals($expected, $signature)) {
            throw new WebhookVerificationException('finlight: invalid webhook signature.');
        }
    }

    private static function verifyTimestamp(string $timestamp): void
    {
        $sentAt = self::parseTimestamp($timestamp);

        if ($sentAt === null) {
            throw new WebhookVerificationException(
                sprintf('finlight: could not parse the webhook timestamp "%s".', $timestamp)
            );
        }

        if (abs(time() - $sentAt) > self::REPLAY_TOLERANCE_SECONDS) {
            throw new WebhookVerificationException('finlight: webhook timestamp outside the allowed tolerance.');
        }
    }

    private static function parseTimestamp(string $timestamp): ?int
    {
        if (preg_match('/^\d{9,11}$/', $timestamp) === 1) {
            return (int) $timestamp;
        }

        try {
            return (new \DateTimeImmutable($timestamp))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    private static function parsePayload(string $rawBody): Article
    {
        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new WebhookVerificationException('finlight: invalid JSON webhook payload.', 0, $error);
        }

        if (!is_array($decoded)) {
            throw new WebhookVerificationException('finlight: expected a JSON object as the webhook payload.');
        }

        try {
            /** @var array<string, mixed> $decoded */
            return Article::fromArray($decoded);
        } catch (MalformedResponseException $error) {
            throw new WebhookVerificationException(
                'finlight: webhook payload is not a valid article: ' . $error->getMessage(),
                0,
                $error
            );
        }
    }
}
