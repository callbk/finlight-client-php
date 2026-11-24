<?php

declare(strict_types=1);

namespace Finlight;

/**
 * A request was rejected locally, before anything was sent: an unknown parameter or a wrong shape.
 * param() is the rejected key, if any.
 */
final class FinlightValidationException extends \InvalidArgumentException implements FinlightException
{
    public function __construct(string $message, private readonly ?string $param = null)
    {
        parent::__construct($message);
    }

    public function kind(): string
    {
        return FinlightException::KIND_VALIDATION;
    }

    public function param(): ?string
    {
        return $this->param;
    }
}
