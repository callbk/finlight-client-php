<?php

declare(strict_types=1);

namespace Finlight\Exception;

/**
 * The API responded with a non-2xx status.
 */
class ApiException extends \RuntimeException implements FinlightException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
