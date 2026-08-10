<?php

declare(strict_types=1);

namespace Finlight\Tests\Service;

use Finlight\Exception\WebhookVerificationException;
use Finlight\Service\WebhookService;
use Finlight\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class WebhookServiceTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    public function testVerifiesSignatureWithoutTimestamp(): void
    {
        $body = self::body();
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $article = WebhookService::constructEvent($body, $signature, self::SECRET);

        self::assertSame('Apple beats quarterly expectations', $article->title);
        self::assertSame('positive', $article->sentiment);
        self::assertSame(0.87, $article->confidence);
    }

    public function testVerifiesTimestampedSignatureWithPrefix(): void
    {
        $body = self::body();
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

        $article = WebhookService::constructEvent($body, $signature, self::SECRET, $timestamp);

        self::assertSame('www.reuters.com', $article->source);
    }

    public function testRejectsInvalidSignature(): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('invalid webhook signature');

        WebhookService::constructEvent(self::body(), str_repeat('a', 64), self::SECRET);
    }

    public function testRejectsSignatureComputedWithoutTheTimestamp(): void
    {
        $body = self::body();
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        // Signed over the body alone while a timestamp header is present.
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $this->expectException(WebhookVerificationException::class);

        WebhookService::constructEvent($body, $signature, self::SECRET, $timestamp);
    }

    public function testRejectsTimestampOutsideTheReplayWindow(): void
    {
        $body = self::body();
        $timestamp = gmdate('Y-m-d\TH:i:s\Z', time() - (WebhookService::REPLAY_TOLERANCE_SECONDS + 60));
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('outside the allowed tolerance');

        WebhookService::constructEvent($body, $signature, self::SECRET, $timestamp);
    }

    public function testAcceptsUnixSecondsTimestamp(): void
    {
        $body = self::body();
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

        $article = WebhookService::constructEvent($body, $signature, self::SECRET, $timestamp);

        self::assertSame('en', $article->language);
    }

    public function testRejectsMalformedJson(): void
    {
        $body = '{not json';
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('invalid JSON webhook payload');

        WebhookService::constructEvent($body, $signature, self::SECRET);
    }

    public function testRejectsPayloadThatIsNotAnArticle(): void
    {
        $body = json_encode(['hello' => 'world'], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('not a valid article');

        WebhookService::constructEvent($body, $signature, self::SECRET);
    }

    public function testRejectsEmptySecret(): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('endpoint secret must not be empty');

        WebhookService::constructEvent(self::body(), 'whatever', '');
    }

    private static function body(): string
    {
        return json_encode(Fixtures::article(), JSON_THROW_ON_ERROR);
    }
}
