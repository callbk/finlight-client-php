<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

use Finlight\Article;

/**
 * The presence view of a decoded model: absent, null, empty ([]) or value, read through the
 * public API only (properties and the *Present() helpers).
 */
final class Presence
{
    /** Keys whose absence the model reports as the language null (R-NULL-1, two states). */
    private const NULL_MEANS_ABSENT = ['companies', 'events', 'companyIds'];

    public static function of(object $model, string $path): string
    {
        $parent = $model;
        $parts = explode('.', $path);
        foreach ($parts as $i => $part) {
            if (preg_match('/^([A-Za-z]+)(?:\[(\d+)\])?$/', $part, $m) !== 1) {
                throw new \LogicException('bad path ' . $path);
            }
            $name = $m[1];
            $index = $m[2] ?? '';
            if (!is_object($parent) || !property_exists($parent, $name)) {
                throw new \LogicException(sprintf('model %s has no property for wire key %s', is_object($parent) ? $parent::class : get_debug_type($parent), $name));
            }
            $value = $parent->{$name};
            $state = self::state($parent, $name, $value);
            if ($index !== '') {
                if ($state !== 'value') {
                    return $state;
                }
                if (!is_array($value)) {
                    throw new \LogicException($path);
                }
                $value = $value[(int) $index];
            }
            if ($i === count($parts) - 1) {
                return $state;
            }
            if ($state !== 'value') {
                return $state;
            }
            $parent = $value;
        }
        throw new \LogicException('unreachable');
    }

    private static function state(object $parent, string $name, mixed $value): string
    {
        if ($parent instanceof Article && $name === 'content') {
            return !$parent->contentPresent() ? 'absent' : ($value === null ? 'null' : 'value');
        }
        if ($parent instanceof Article && $name === 'sentiment') {
            return !$parent->sentimentPresent() ? 'absent' : ($value === null ? 'null' : 'value');
        }
        if ($value === null) {
            return in_array($name, self::NULL_MEANS_ABSENT, true) ? 'absent' : 'null';
        }

        return $value === [] ? 'empty' : 'value';
    }

    /** Presence of a gated top-level key through the public helpers only. */
    public static function helper(Article $article, string $key): string
    {
        return match ($key) {
            'content' => !$article->contentPresent() ? 'absent' : ($article->content === null ? 'null' : 'value'),
            'sentiment' => !$article->sentimentPresent() ? 'absent' : ($article->sentiment === null ? 'null' : 'value'),
            'companies' => !$article->companiesPresent() ? 'absent' : ($article->companies === [] ? 'empty' : 'value'),
            'events' => !$article->eventsPresent() ? 'absent' : ($article->events === [] ? 'empty' : 'value'),
            default => throw new \LogicException($key),
        };
    }
}
