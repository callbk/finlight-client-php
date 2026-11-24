<?php

declare(strict_types=1);

namespace Finlight;

/**
 * The request failed on the network level. The error of the HTTP client is getPrevious().
 */
class FinlightNetworkException extends \RuntimeException implements FinlightException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function kind(): string
    {
        return FinlightException::KIND_NETWORK;
    }
}
