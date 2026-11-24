<?php

declare(strict_types=1);

namespace Finlight\Internal;

use Finlight\CursorInvalidException;
use Finlight\CursorMismatchException;
use Finlight\FieldIssue;
use Finlight\FinlightApiException;
use Finlight\ForbiddenException;
use Finlight\InternalServerException;
use Finlight\NotFoundException;
use Finlight\RateLimitedException;
use Finlight\RateLimitType;
use Finlight\UnauthorizedException;
use Finlight\ValidationFailedException;

/**
 * Builds FinlightApiException from an error answer. The one place that reads error bodies and sorts
 * a 429 into monthly, burst or unknown.
 *
 * @internal
 */
final class Problem
{
    /** @var array<string, class-string<FinlightApiException>> */
    private const CODE_CLASSES = [
        'VALIDATION_FAILED' => ValidationFailedException::class,
        'CURSOR_INVALID' => CursorInvalidException::class,
        'CURSOR_MISMATCH' => CursorMismatchException::class,
        'UNAUTHORIZED' => UnauthorizedException::class,
        'FORBIDDEN' => ForbiddenException::class,
        'NOT_FOUND' => NotFoundException::class,
        'INTERNAL_ERROR' => InternalServerException::class,
    ];

    /** @var array<int, class-string<FinlightApiException>> */
    private const STATUS_CLASSES = [
        400 => ValidationFailedException::class,
        401 => UnauthorizedException::class,
        403 => ForbiddenException::class,
        404 => NotFoundException::class,
    ];

    /**
     * Sort a 429. The problem member limitType wins and is kept as sent (open list). Without it the
     * detail text decides, compared as exact, case-sensitive substrings.
     */
    public static function classifyRateLimit(mixed $limitType, mixed $detail): string
    {
        if (is_string($limitType) && $limitType !== '') {
            return $limitType;
        }
        if (is_string($detail)) {
            if (str_contains($detail, 'Exceeded token limit')) {
                return RateLimitType::MONTHLY;
            }
            if (str_contains($detail, 'Exceeded rate limit')) {
                return RateLimitType::BURST;
            }
        }

        return RateLimitType::UNKNOWN;
    }

    /**
     * Reading order: application/problem+json, then the NestJS form {statusCode, message}, then a
     * body that is not JSON (status plus text).
     */
    public static function fromResponse(int $status, ?string $contentType, string $body, ?string $sentRequestId, ?float $retryAfter): FinlightApiException
    {
        $parsed = null;
        if (trim($body) !== '') {
            try {
                $parsed = Json::decode($body);
            } catch (\JsonException) {
                $parsed = null;
            }
        }
        $ctype = strtolower($contentType ?? '');
        $isObject = Json::isObject($parsed) && $parsed !== [];

        if ($isObject && (str_contains($ctype, 'problem+json') || (array_key_exists('code', $parsed) && (array_key_exists('type', $parsed) || array_key_exists('title', $parsed))))) {
            $code = self::str($parsed['code'] ?? null);
            $detail = self::str($parsed['detail'] ?? null);
            $message = sprintf('finlight API error %d', $status) . ($code !== null ? ' ' . $code : '') . ($detail !== null ? ': ' . $detail : '');
            $args = [
                $message,
                $status,
                $code,
                self::str($parsed['type'] ?? null),
                self::str($parsed['title'] ?? null),
                $detail,
                self::str($parsed['requestId'] ?? null) ?? $sentRequestId,
                self::issues($parsed['errors'] ?? null),
                $body,
            ];
            if ($code === 'RATE_LIMITED' || ($code === null && $status === 429)) {
                return new RateLimitedException(...$args, previous: null, limitType: self::classifyRateLimit($parsed['limitType'] ?? null, $detail), retryAfter: $retryAfter);
            }
            $class = self::CODE_CLASSES[$code ?? ''] ?? FinlightApiException::class;

            return new $class(...$args);
        }

        if ($isObject && array_key_exists('statusCode', $parsed) && array_key_exists('message', $parsed)) {
            $raw = $parsed['message'];
            $issues = [];
            if (is_array($raw)) {
                $texts = array_values(array_map(static fn (mixed $m): string => is_scalar($m) ? (string) $m : Json::encode($m), $raw));
                $detail = implode('; ', $texts);
                foreach ($texts as $text) {
                    $issues[] = new FieldIssue(null, $text);
                }
            } else {
                $detail = is_scalar($raw) ? (string) $raw : null;
            }
            $message = sprintf('finlight API error %d', $status) . ($detail !== null && $detail !== '' ? ': ' . $detail : '');
            $title = self::str($parsed['error'] ?? null);
            if ($status === 429) {
                return new RateLimitedException($message, $status, null, null, $title, $detail, $sentRequestId, $issues, $body, null, self::classifyRateLimit(null, $detail), $retryAfter);
            }
            $class = self::STATUS_CLASSES[$status] ?? FinlightApiException::class;

            return new $class($message, $status, null, null, $title, $detail, $sentRequestId, $issues, $body);
        }

        $snippet = trim($body);
        if (strlen($snippet) > 200) {
            $snippet = substr($snippet, 0, 200) . '...';
        }
        $message = sprintf('finlight API error %d', $status) . ($snippet !== '' ? ': ' . $snippet : '');
        if ($status === 429) {
            return new RateLimitedException($message, $status, null, null, null, $body === '' ? null : $body, $sentRequestId, [], $body, null, RateLimitType::UNKNOWN, $retryAfter);
        }

        return new FinlightApiException($message, $status, null, null, null, $body === '' ? null : $body, $sentRequestId, [], $body);
    }

    /**
     * The error of a connection.error frame: the kind comes from the code. raw() is the frame text
     * as received.
     */
    public static function fromConnectionError(?string $code, ?string $text, mixed $details, string $frame): FinlightApiException
    {
        $message = 'finlight stream error' . ($code !== null ? ' ' . $code : '') . ($text !== null ? ': ' . $text : '');
        $class = self::CODE_CLASSES[$code ?? ''] ?? FinlightApiException::class;

        return new $class($message, null, $code, null, null, $text, null, self::issues($details), $frame);
    }

    /**
     * @return list<FieldIssue>
     */
    public static function issues(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $issues = [];
        foreach ($value as $item) {
            if (!Json::isObject($item)) {
                continue;
            }
            $allowed = $item['allowedValues'] ?? null;
            $constraints = $item['constraints'] ?? null;
            $constraintMap = null;
            if (Json::isObject($constraints)) {
                $constraintMap = [];
                foreach ($constraints as $rule => $text) {
                    $constraintMap[(string) $rule] = is_scalar($text) ? (string) $text : Json::encode($text);
                }
            }
            $issue = self::str($item['issue'] ?? null);
            if ($issue === null && $constraintMap !== null && $constraintMap !== []) {
                $issue = implode('; ', $constraintMap);
            }
            $issues[] = new FieldIssue(
                self::str($item['field'] ?? null),
                $issue,
                is_array($allowed) ? array_values(array_filter($allowed, 'is_string')) : null,
                $constraintMap,
            );
        }

        return $issues;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
