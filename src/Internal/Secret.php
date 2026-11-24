<?php

declare(strict_types=1);

namespace Finlight\Internal;

/**
 * Holds a secret (the API key) outside of any object property, so var_dump(), print_r(),
 * var_export(), an (array) cast and json_encode() of the holder never show it. A shallow clone
 * of the holder shares the same Secret and keeps working.
 *
 * @internal
 */
final class Secret
{
    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $values = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    public function reveal(): string
    {
        $values = self::$values;
        if ($values === null || !isset($values[$this])) {
            throw new \LogicException('finlight: the secret is gone (was the config unserialized?)');
        }

        return $values[$this];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('finlight: a config with an API key cannot be serialized');
    }
}
