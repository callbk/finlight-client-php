<?php

declare(strict_types=1);

namespace Finlight\Exception;

/**
 * HTTP 429, raised once the client's own retries are exhausted.
 */
final class RateLimitException extends ApiException
{
    public function __construct(
        string $message,
        int $statusCode,
        ?string $responseBody = null,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $responseBody, $previous);
    }
}
