<?php

declare(strict_types=1);

namespace Finlight\Internal;

use Finlight\FinlightDecodeException;

/**
 * Typed reads from a decoded JSON object. Keys are matched exactly. Values are never coerced:
 * a string "0.95" where a number is expected is an error. A missing key and null both read as
 * null for optional values.
 *
 * @internal
 */
final class Wire
{
    /**
     * @param array<string, mixed> $obj
     */
    public static function requiredString(array $obj, string $key, string $path): string
    {
        $value = $obj[$key] ?? null;
        if (!is_string($value)) {
            throw self::fail($path . '.' . $key, $value, $value === null ? 'is missing or null' : 'is not a string');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $obj
     */
    public static function requiredInt(array $obj, string $key, string $path): int
    {
        $value = $obj[$key] ?? null;
        if (!is_int($value)) {
            throw self::fail($path . '.' . $key, $value, $value === null ? 'is missing or null' : 'is not an integer');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $obj
     */
    public static function string(array $obj, string $key, string $path): ?string
    {
        $value = $obj[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw self::fail($path . '.' . $key, $value, 'is not a string');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $obj
     */
    public static function int(array $obj, string $key, string $path): ?int
    {
        $value = $obj[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw self::fail($path . '.' . $key, $value, 'is not an integer');
        }

        return $value;
    }

    /**
     * A JSON number. Integers are returned as floats; the value is not rounded.
     *
     * @param array<string, mixed> $obj
     */
    public static function number(array $obj, string $key, string $path): ?float
    {
        $value = $obj[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !is_float($value)) {
            throw self::fail($path . '.' . $key, $value, 'is not a number');
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $obj
     */
    public static function bool(array $obj, string $key, string $path): ?bool
    {
        $value = $obj[$key] ?? null;
        if ($value !== null && !is_bool($value)) {
            throw self::fail($path . '.' . $key, $value, 'is not a boolean');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $obj
     *
     * @return array<string, mixed>|null
     */
    public static function object(array $obj, string $key, string $path): ?array
    {
        $value = $obj[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!Json::isObject($value)) {
            throw self::fail($path . '.' . $key, $value, 'is not an object');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $obj
     *
     * @return list<mixed>|null
     */
    public static function list(array $obj, string $key, string $path): ?array
    {
        $value = $obj[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw self::fail($path . '.' . $key, $value, 'is not an array');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $obj
     *
     * @return list<string>|null
     */
    public static function strings(array $obj, string $key, string $path): ?array
    {
        $items = self::list($obj, $key, $path);
        if ($items === null) {
            return null;
        }
        foreach ($items as $i => $item) {
            if (!is_string($item)) {
                throw self::fail(sprintf('%s.%s[%d]', $path, $key, $i), $item, 'is not a string');
            }
        }

        /** @var list<string> $items */
        return $items;
    }

    /**
     * @param array<string, mixed> $obj
     *
     * @return list<int>|null
     */
    public static function ints(array $obj, string $key, string $path): ?array
    {
        $items = self::list($obj, $key, $path);
        if ($items === null) {
            return null;
        }
        foreach ($items as $i => $item) {
            if (!is_int($item)) {
                throw self::fail(sprintf('%s.%s[%d]', $path, $key, $i), $item, 'is not an integer');
            }
        }

        /** @var list<int> $items */
        return $items;
    }

    /**
     * Each entry of a JSON array of objects, decoded with $read.
     *
     * @template T
     *
     * @param array<string, mixed>                       $obj
     * @param callable(array<string, mixed>, string): T $read
     *
     * @return list<T>|null
     */
    public static function objects(array $obj, string $key, string $path, callable $read): ?array
    {
        $items = self::list($obj, $key, $path);
        if ($items === null) {
            return null;
        }
        $out = [];
        foreach ($items as $i => $item) {
            $itemPath = sprintf('%s.%s[%d]', $path, $key, $i);
            if (!Json::isObject($item)) {
                throw self::fail($itemPath, $item, 'is not an object');
            }
            $out[] = $read($item, $itemPath);
        }

        return $out;
    }

    public static function fail(string $path, mixed $value, string $what): FinlightDecodeException
    {
        return new FinlightDecodeException(sprintf('finlight: %s %s', $path, $what), $path, $value);
    }
}
