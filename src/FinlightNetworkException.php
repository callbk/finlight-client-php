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

    /**
     * var_dump() and print_r() show the message, the kind and the class of the HTTP client's
     * error, never that error's properties: it holds the request with the X-API-KEY header.
     * getPrevious() still returns it.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $previous = $this->getPrevious();

        return [
            'message' => $this->getMessage(),
            'kind' => $this->kind(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'previous' => $previous === null ? null : $previous::class,
        ];
    }
}
