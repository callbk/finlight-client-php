<?php

declare(strict_types=1);

namespace Finlight\Tests\Conformance;

use Finlight\CursorInvalidException;
use Finlight\CursorMismatchException;
use Finlight\FinlightApiException;
use Finlight\FinlightException;
use Finlight\ForbiddenException;
use Finlight\InternalServerException;
use Finlight\NotFoundException;
use Finlight\RateLimitedException;
use Finlight\Tests\Support\Kit;
use Finlight\Tests\Support\MockHttpClient;
use Finlight\Tests\Support\TestClient;
use Finlight\Tests\Support\Wire;
use Finlight\UnauthorizedException;
use Finlight\ValidationFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * E-01 .. E-25: error kinds and retry classes. The mock counts the attempts per fixture.
 */
final class ErrorsTest extends TestCase
{
    private const CLASSES = [
        'VALIDATION_FAILED' => ValidationFailedException::class,
        'CURSOR_INVALID' => CursorInvalidException::class,
        'CURSOR_MISMATCH' => CursorMismatchException::class,
        'UNAUTHORIZED' => UnauthorizedException::class,
        'FORBIDDEN' => ForbiddenException::class,
        'NOT_FOUND' => NotFoundException::class,
        'RATE_LIMITED' => RateLimitedException::class,
        'INTERNAL_ERROR' => InternalServerException::class,
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function errorCases(): array
    {
        return Kit::cases('fixtures/errors', ['retry-classes.json']);
    }

    #[DataProvider('errorCases')]
    public function testErrorCase(string $rel): void
    {
        $fx = Kit::load($rel);
        $req = Kit::obj($fx['request']);
        $resp = Kit::obj($fx['response']);
        $expect = Kit::obj($fx['expect']);
        $headers = Kit::obj($req['headers']);

        // E-12 sends no key; the SDK always sends one. What is tested is the server's answer.
        $t = new TestClient(['apiKey' => isset($headers['X-API-KEY']) ? Kit::str($headers['X-API-KEY']) : 'sk_missing']);
        $t->http->always(static fn () => MockHttpClient::fromFixture($resp));
        $body = null;
        if (isset($req['rawBody'])) {
            $body = Kit::str($req['rawBody']);
        } elseif (array_key_exists('body', $req)) {
            $body = $req['body'] === [] ? '{}' : Kit::json($req['body']);
        }
        $query = isset($req['query']) ? Wire::query(Kit::str($req['query'])) : [];

        try {
            $t->transport()->call(Kit::str($req['method']), Kit::str($req['path']), $query, $body);
            self::fail('no error');
        } catch (FinlightApiException $err) {
        }

        self::assertInstanceOf(FinlightException::class, $err);
        self::assertSame('api', $err->kind());
        self::assertSame($resp['status'], $err->status());
        self::assertSame($expect['apiKind'], $err->apiKind());
        $sentIds = array_unique(array_map(static fn ($r) => $r->getHeaderLine('X-Request-Id'), $t->http->requests));
        self::assertCount(1, $sentIds, 'one X-Request-Id per logical call, the same on every retry');
        $sentId = reset($sentIds);

        $code = $expect['code'];
        $respBody = $resp['body'];
        if ($code === 'NESTJS_FALLBACK') {
            $respBody = Kit::obj($respBody);
            self::assertNull($err->code());
            self::assertSame($respBody['message'], $err->detail());
            self::assertSame($sentId, $err->requestId());
            self::assertSame(match ($resp['status']) {
                400 => ValidationFailedException::class,
                404 => NotFoundException::class,
                default => FinlightApiException::class,
            }, $err::class);
        } elseif ($code === null) {
            self::assertSame(FinlightApiException::class, $err::class);
            self::assertNull($err->code());
            self::assertSame($respBody, $err->raw(), 'the raw text is kept');
            self::assertSame($sentId, $err->requestId());
        } else {
            $respBody = Kit::obj($respBody);
            self::assertSame(self::CLASSES[Kit::str($code)], $err::class);
            self::assertSame($code, $err->code());
            self::assertSame($respBody['detail'], $err->detail());
            self::assertSame($respBody['title'], $err->title());
            self::assertSame($respBody['type'], $err->type());
            self::assertSame($respBody['requestId'], $err->requestId());
            $wantIssues = [];
            foreach (Kit::list($respBody['errors'] ?? []) as $issue) {
                $issue = Kit::obj($issue);
                $wantIssues[] = [$issue['field'] ?? null, $issue['issue'] ?? null, $issue['allowedValues'] ?? null];
            }
            self::assertSame($wantIssues, array_map(static fn ($e) => [$e->field, $e->issue, $e->allowedValues], $err->errors()));
            self::assertSame($respBody, $err->rawJson());
        }

        $attempts = count($t->http->requests);
        switch ($expect['retry']) {
            case 'never':
                self::assertSame(1, $attempts);
                self::assertSame([], $t->clock->sleeps);
                break;
            case 'once-after-burst-wait':
                self::assertInstanceOf(RateLimitedException::class, $err);
                self::assertSame('burst', $err->limitType());
                $retryAfter = Kit::obj($resp['headers'])['retry-after'] ?? null;
                self::assertSame($retryAfter === null ? null : (float) Kit::str($retryAfter), $err->retryAfter());
                self::assertSame(2, $attempts);
                self::assertSame([$retryAfter === null ? 10.0 : (float) Kit::str($retryAfter)], $t->clock->sleeps);
                break;
            case 'backoff':
                self::assertSame(4, $attempts);
                self::assertCount(3, $t->clock->sleeps);
                $sorted = $t->clock->sleeps;
                sort($sorted);
                self::assertSame($sorted, $t->clock->sleeps, 'waits grow');
                break;
            default:
                self::fail('unknown retry class');
        }

        switch ($fx['id']) {
            case 'E-02':
                self::assertGreaterThan(1, count($err->errors()));
                self::assertMatchesRegularExpression('/^\d+ request fields are not valid\.$/', (string) $err->detail());
                break;
            case 'E-18':
                self::assertInstanceOf(RateLimitedException::class, $err);
                self::assertSame('monthly', $err->limitType());
                self::assertNull($err->retryAfter());
                break;
            case 'E-23':
                self::assertStringStartsWith('req_', (string) $err->requestId());
                break;
        }
    }
}
