<?php

declare(strict_types=1);

namespace Finlight\Internal;

use Finlight\FinlightValidationException;

/**
 * JSON with fixed flags: errors throw, slashes and Unicode are written as they are, and an empty
 * object is written as {} (pass a stdClass).
 *
 * @internal
 */
final class Json
{
    private const ENCODE = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::ENCODE);
    }

    /**
     * Encode a request body or subscribe message. The parameters were checked before, so a failure
     * here is a safety net: it still throws a FinlightValidationException, never a JsonException.
     */
    public static function encodeRequest(mixed $value): string
    {
        try {
            return json_encode($value, self::ENCODE);
        } catch (\JsonException $e) {
            throw new FinlightValidationException('finlight: the request cannot be written as JSON (' . $e->getMessage() . ')');
        }
    }

    /**
     * Objects become associative arrays. Integers beyond 64 bit stay strings, so they fail the
     * type checks instead of turning into floats.
     *
     * @throws \JsonException
     */
    public static function decode(string $text): mixed
    {
        return json_decode($text, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
    }

    /**
     * True when the text, after leading whitespace, starts a JSON object. Needed because both {}
     * and [] decode to an empty PHP array.
     */
    public static function looksLikeObject(string $text): bool
    {
        return str_starts_with(ltrim($text, " \t\n\r"), '{');
    }

    /**
     * An array that came from a JSON object (or an empty one).
     *
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    public static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
