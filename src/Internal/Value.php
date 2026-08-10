<?php

declare(strict_types=1);

namespace Finlight\Internal;

use Finlight\Exception\MalformedResponseException;

/**
 * Typed accessors for decoded JSON. Required fields throw, optional fields
 * fall back to null.
 *
 * @internal
 */
final class Value
{
    /**
     * @param array<string, mixed> $data
     */
    public static function string(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value)) {
            throw self::mismatch($context, $key, 'string', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key, string $context): int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw self::mismatch($context, $key, 'int', $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key, string $context): bool
    {
        $value = $data[$key] ?? null;

        if (!is_bool($value)) {
            throw self::mismatch($context, $key, 'bool', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function dateTime(array $data, string $key, string $context): \DateTimeImmutable
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw self::mismatch($context, $key, 'date string', $value);
        }

        $parsed = self::parseDate($value);

        if ($parsed === null) {
            throw new MalformedResponseException(
                sprintf('%s: could not parse "%s" at "%s" as a date.', $context, $value, $key)
            );
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Confidence values are delivered as decimal strings.
     *
     * @param array<string, mixed> $data
     */
    public static function nullableFloat(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableBool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableDateTime(array $data, string $key): ?\DateTimeImmutable
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || $value === '') {
            return null;
        }

        return self::parseDate($value);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>|null
     */
    public static function nullableStringList(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            return null;
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>|null
     */
    public static function nullableObjectList(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            return null;
        }

        $items = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public static function nullableObject(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function mismatch(string $context, string $key, string $expected, mixed $actual): MalformedResponseException
    {
        return new MalformedResponseException(
            sprintf('%s: expected %s at "%s", got %s.', $context, $expected, $key, get_debug_type($actual))
        );
    }
}
