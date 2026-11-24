<?php

declare(strict_types=1);

namespace Finlight;

/**
 * An answer, frame or webhook body could not be read into the SDK models.
 *
 * path() is the JSON path of the value that did not fit (or null), raw() that value.
 */
final class FinlightDecodeException extends \RuntimeException implements FinlightException
{
    public function __construct(
        string $message,
        private readonly ?string $path = null,
        private readonly mixed $raw = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function kind(): string
    {
        return FinlightException::KIND_DECODE;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function raw(): mixed
    {
        return $this->raw;
    }
}
