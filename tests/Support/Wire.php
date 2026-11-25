<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

use Psr\Http\Message\RequestInterface;

/**
 * Reads what the SDK put on the wire.
 */
final class Wire
{
    /**
     * Query parameters as decoded (name, value) pairs, in order.
     *
     * @return list<array{string, string}>
     */
    public static function query(string $query): array
    {
        if ($query === '') {
            return [];
        }
        $out = [];
        foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);
            $out[] = [rawurldecode($parts[0]), rawurldecode($parts[1] ?? '')];
        }

        return $out;
    }

    public static function body(RequestInterface $request): mixed
    {
        $text = (string) $request->getBody();

        return $text === '' ? null : json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
    }

    /** JSON with sorted keys, for comparisons where key order is free. */
    public static function canonical(mixed $value): string
    {
        return Kit::json(self::sortKeys($value));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
