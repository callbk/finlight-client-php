<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

/**
 * Access to the vendored contract kit in conformance/.
 */
final class Kit
{
    public const KEY = 'sk_0000000000000000000000000000000000000000000000000000000000000001';

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function dir(): string
    {
        return self::root() . '/conformance';
    }

    /**
     * @return array<string, mixed>
     */
    public static function load(string $rel): array
    {
        $text = file_get_contents(self::dir() . '/' . $rel);
        if ($text === false) {
            throw new \RuntimeException('missing kit file ' . $rel);
        }
        $data = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('kit file is not an object: ' . $rel);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Fixtures of a kit folder by case id (sorted), as data-provider rows [relative path].
     *
     * @param list<string> $exclude
     *
     * @return array<string, array{string}>
     */
    public static function cases(string $folder, array $exclude = []): array
    {
        $out = [];
        foreach (glob(self::dir() . '/' . $folder . '/*.json') ?: [] as $path) {
            if (in_array(basename($path), $exclude, true)) {
                continue;
            }
            $rel = $folder . '/' . basename($path);
            $id = self::load($rel)['id'] ?? basename($path, '.json');
            $out[is_string($id) ? $id : basename($path)] = [$rel];
        }
        ksort($out);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function obj(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException('expected an object, got ' . get_debug_type($value));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<mixed>
     */
    public static function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException('expected a list, got ' . get_debug_type($value));
        }

        return $value;
    }

    public static function str(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('expected a string, got ' . get_debug_type($value));
        }

        return $value;
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }
}
