<?php

declare(strict_types=1);

namespace Finlight\Internal;

/**
 * Keeps the JSON object a model was read from and writes the model back to its wire form.
 *
 * Every wire key maps to the property of the same name (R-NAMES-2), so toWire() can walk the known
 * keys. Keys that were absent stay absent, null stays null, unknown keys are left out.
 *
 * @internal
 */
trait WireObject
{
    /** @var array<string, mixed> */
    private readonly array $wire;

    /**
     * The model in its wire form: only the known keys, exactly as present in the answer.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $out = [];
        foreach (self::WIRE_KEYS as $key) {
            if (!array_key_exists($key, $this->wire)) {
                continue;
            }
            $raw = $this->wire[$key];
            $out[$key] = $raw === null ? null : self::wireValue($this->{$key}, $raw);
        }

        return $out;
    }

    private static function wireValue(mixed $value, mixed $raw): mixed
    {
        if (is_object($value) && method_exists($value, 'toWire')) {
            return $value->toWire();
        }
        if (is_array($value) && $value !== [] && is_object(reset($value))) {
            $out = [];
            foreach ($value as $item) {
                $out[] = is_object($item) && method_exists($item, 'toWire') ? $item->toWire() : $item;
            }

            return $out;
        }

        return $raw;
    }
}
