<?php

declare(strict_types=1);

namespace Finlight\Internal;

use Finlight\FinlightValidationException;

/**
 * Builds request bodies and subscribe frames from caller arrays.
 *
 * Keys come from fixed lists; an unknown key is rejected before anything is sent. Only keys the
 * caller set are written; null means "not set". Values of vocabularies are not checked, only the
 * shape is. The result is built key by key, never by copying the caller's array.
 *
 * @internal
 */
final class Params
{
    /** @var array<string, string> wire name => kind, in the order they are written */
    public const SEARCH = [
        'query' => 'string',
        'sources' => 'strings',
        'optInSources' => 'strings',
        'excludeSources' => 'strings',
        'sourceTypes' => 'strings',
        'excludeSourceTypes' => 'strings',
        'from' => 'time',
        'to' => 'time',
        'languages' => 'languages',
        'tickers' => 'strings',
        'countries' => 'strings',
        'categories' => 'strings',
        'hasContent' => 'bool',
        'roles' => 'strings',
        'minRelevance' => 'unit',
        'eventTypes' => 'strings',
        'eventDirections' => 'strings',
        'minImportance' => 'string',
        'sectors' => 'strings',
        'companyCountries' => 'strings',
        'exchanges' => 'strings',
        'orderBy' => 'string',
        'order' => 'string',
        'limit' => 'limit',
        'cursor' => 'string',
        'include' => 'include',
    ];

    /** @var array<string, string> */
    public const STREAM = [
        'include' => 'include',
        'query' => 'string',
        'sources' => 'strings',
        'excludeSources' => 'strings',
        'optInSources' => 'strings',
        'language' => 'string',
        'tickers' => 'strings',
        'countries' => 'strings',
        'categories' => 'strings',
        'sourceTypes' => 'strings',
        'excludeSourceTypes' => 'strings',
        'excludeEmptyContent' => 'bool',
        'includeUpdates' => 'bool',
    ];

    /** @var array<string, string> raw streams know no include and no enriched-only keys */
    public const RAW_STREAM = [
        'query' => 'string',
        'sources' => 'strings',
        'excludeSources' => 'strings',
        'sourceTypes' => 'strings',
        'excludeSourceTypes' => 'strings',
        'language' => 'string',
        'includeUpdates' => 'bool',
    ];

    /**
     * @param array<string, string> $fields
     * @param array<mixed>          $params
     *
     * @return array<string, mixed>
     */
    public static function build(array $fields, array $params, string $what): array
    {
        foreach (array_keys($params) as $key) {
            if (!is_string($key) || !array_key_exists($key, $fields)) {
                throw new FinlightValidationException(
                    sprintf('finlight: unknown %s parameter "%s"', $what, (string) $key),
                    (string) $key,
                );
            }
        }
        $out = [];
        foreach ($fields as $key => $kind) {
            if (!array_key_exists($key, $params) || $params[$key] === null) {
                continue;
            }
            $value = self::convert($key, $kind, $params[$key]);
            if ($kind === 'include' && $value === []) {
                continue; // an empty include is left out
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * The include list for a GET query: one parameter, comma-joined, left out when empty.
     *
     * @return list<array{string, string}>
     */
    public static function includeQuery(mixed $include): array
    {
        if ($include === null) {
            return [];
        }
        $items = self::includeList($include);

        return $items === [] ? [] : [['include', implode(',', $items)]];
    }

    /** Number of characters of a UTF-8 string (bytes when it is not valid UTF-8). */
    public static function charLength(string $text): int
    {
        $count = preg_match_all('/./us', $text);

        return $count === false ? strlen($text) : $count;
    }

    /**
     * @return list<int>
     */
    public static function companyIds(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new FinlightValidationException('finlight: ids must be a list of integers', 'ids');
        }
        if (count($ids) < 1 || count($ids) > 10) {
            throw new FinlightValidationException('finlight: ids takes 1 to 10 company ids', 'ids');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new FinlightValidationException('finlight: ids must be integers >= 1', 'ids');
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    public static function includeList(mixed $value): array
    {
        $items = self::strings('include', $value);
        if (count($items) > 3) {
            throw new FinlightValidationException('finlight: include takes at most 3 values', 'include');
        }

        return $items;
    }

    /**
     * A string that goes into a JSON body must be valid UTF-8; checked when the call is made.
     */
    public static function utf8(string $key, string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new FinlightValidationException(sprintf('finlight: %s is not valid UTF-8', $key), $key);
        }

        return $value;
    }

    private static function convert(string $key, string $kind, mixed $value): mixed
    {
        switch ($kind) {
            case 'string':
                if (!is_string($value)) {
                    throw new FinlightValidationException(sprintf('finlight: %s must be a string', $key), $key);
                }

                return self::utf8($key, $value);
            case 'strings':
                return self::strings($key, $value);
            case 'languages':
                $items = self::strings($key, $value);
                if ($items === []) {
                    throw new FinlightValidationException('finlight: languages must not be empty', $key);
                }

                return $items;
            case 'include':
                return self::includeList($value);
            case 'bool':
                if (!is_bool($value)) {
                    throw new FinlightValidationException(sprintf('finlight: %s must be true or false', $key), $key);
                }

                return $value;
            case 'time':
                if (is_string($value)) {
                    return self::utf8($key, $value); // a caller's string is sent unchanged
                }
                if ($value instanceof \DateTimeInterface) {
                    return Time::format($value);
                }
                throw new FinlightValidationException(sprintf('finlight: %s must be a string or a DateTimeInterface', $key), $key);
            case 'unit':
                if (!is_int($value) && !is_float($value)) {
                    throw new FinlightValidationException(sprintf('finlight: %s must be a number', $key), $key);
                }
                if ($value < 0 || $value > 1 || is_nan((float) $value)) {
                    throw new FinlightValidationException(sprintf('finlight: %s must be between 0 and 1', $key), $key);
                }

                return $value;
            case 'limit':
                if (!is_int($value)) {
                    throw new FinlightValidationException('finlight: limit must be an integer', $key);
                }
                if ($value < 1 || $value > 100) {
                    throw new FinlightValidationException('finlight: limit must be between 1 and 100', $key);
                }

                return $value;
        }
        throw new \LogicException('unknown kind ' . $kind);
    }

    /**
     * @return list<string>
     */
    private static function strings(string $key, mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new FinlightValidationException(sprintf('finlight: %s must be a list of strings', $key), $key);
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new FinlightValidationException(sprintf('finlight: %s must contain only strings', $key), $key);
            }
            self::utf8($key, $item);
        }

        /** @var list<string> $value */
        return $value;
    }
}
