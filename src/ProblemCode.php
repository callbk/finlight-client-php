<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known problem codes of FinlightApiException::code(). Open: unknown codes stay on FinlightApiException.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class ProblemCode
{
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const CURSOR_INVALID = 'CURSOR_INVALID';
    public const CURSOR_MISMATCH = 'CURSOR_MISMATCH';
    public const UNAUTHORIZED = 'UNAUTHORIZED';
    public const FORBIDDEN = 'FORBIDDEN';
    public const NOT_FOUND = 'NOT_FOUND';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    /** @var list<string> */
    public const ALL = [self::VALIDATION_FAILED, self::CURSOR_INVALID, self::CURSOR_MISMATCH, self::UNAUTHORIZED, self::FORBIDDEN, self::NOT_FOUND, self::RATE_LIMITED, self::INTERNAL_ERROR];

    /** @var array<string, string> problem code => type URL */
    public const TYPES = [
        self::VALIDATION_FAILED => 'https://docs.finlight.me/errors/validation-failed',
        self::CURSOR_INVALID => 'https://docs.finlight.me/errors/cursor-invalid',
        self::CURSOR_MISMATCH => 'https://docs.finlight.me/errors/cursor-mismatch',
        self::UNAUTHORIZED => 'https://docs.finlight.me/errors/unauthorized',
        self::FORBIDDEN => 'https://docs.finlight.me/errors/forbidden',
        self::NOT_FOUND => 'https://docs.finlight.me/errors/not-found',
        self::RATE_LIMITED => 'https://docs.finlight.me/errors/rate-limited',
        self::INTERNAL_ERROR => 'https://docs.finlight.me/errors/internal-error',
    ];

    private function __construct()
    {
    }
}
