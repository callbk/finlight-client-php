<?php

declare(strict_types=1);

namespace Finlight;

/**
 * RATE_LIMITED: the monthly quota or the burst limit is reached.
 *
 * limitType() is "monthly", "burst", "unknown" or a newer value the server sent (kept as a string,
 * see RateLimitType). retryAfter() is the Retry-After header in seconds (sent on burst only).
 * A monthly 429 is never retried; a burst 429 is retried once after Retry-After by default.
 */
class RateLimitedException extends FinlightApiException
{
    /**
     * @param list<FieldIssue> $errors
     */
    public function __construct(
        string $message,
        ?int $status = null,
        ?string $problemCode = null,
        ?string $type = null,
        ?string $title = null,
        ?string $detail = null,
        ?string $requestId = null,
        array $errors = [],
        ?string $raw = null,
        ?\Throwable $previous = null,
        private readonly string $limitType = RateLimitType::UNKNOWN,
        private readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $status, $problemCode, $type, $title, $detail, $requestId, $errors, $raw, $previous);
    }

    public function apiKind(): string
    {
        return self::API_KIND_RATE_LIMITED;
    }

    public function limitType(): string
    {
        return $this->limitType;
    }

    /** Seconds from the Retry-After header, or null when there was none. */
    public function retryAfter(): ?float
    {
        return $this->retryAfter;
    }
}
