<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Json;

/**
 * An error answered by the finlight API: a REST error status or a connection.error frame.
 *
 * Known kinds have their own subclass (ValidationFailedException, NotFoundException, ...).
 * apiKind() names the kind as text: validation_failed, cursor_invalid, cursor_mismatch,
 * unauthorized, forbidden, not_found, rate_limited, internal_error, or other (this class).
 *
 * getCode() is PHP's numeric exception code and is not used; the problem code is code().
 */
class FinlightApiException extends \RuntimeException implements FinlightException
{
    public const API_KIND_VALIDATION_FAILED = 'validation_failed';
    public const API_KIND_CURSOR_INVALID = 'cursor_invalid';
    public const API_KIND_CURSOR_MISMATCH = 'cursor_mismatch';
    public const API_KIND_UNAUTHORIZED = 'unauthorized';
    public const API_KIND_FORBIDDEN = 'forbidden';
    public const API_KIND_NOT_FOUND = 'not_found';
    public const API_KIND_RATE_LIMITED = 'rate_limited';
    public const API_KIND_INTERNAL_ERROR = 'internal_error';
    public const API_KIND_OTHER = 'other';

    /**
     * @param list<FieldIssue> $errors
     */
    public function __construct(
        string $message,
        private readonly ?int $status = null,
        private readonly ?string $problemCode = null,
        private readonly ?string $type = null,
        private readonly ?string $title = null,
        private readonly ?string $detail = null,
        private readonly ?string $requestId = null,
        private readonly array $errors = [],
        private readonly ?string $raw = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function kind(): string
    {
        return FinlightException::KIND_API;
    }

    public function apiKind(): string
    {
        return self::API_KIND_OTHER;
    }

    /** HTTP status, or null for a connection.error frame. */
    public function status(): ?int
    {
        return $this->status;
    }

    /** Problem code (open string, see ProblemCode), or null when the answer had none. */
    public function code(): ?string
    {
        return $this->problemCode;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function detail(): ?string
    {
        return $this->detail;
    }

    /** The requestId of the answer, or the X-Request-Id the SDK sent. */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /** @return list<FieldIssue> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** The error body as text, exactly as received. */
    public function raw(): ?string
    {
        return $this->raw;
    }

    /** The error body parsed as JSON, or null when it is not JSON. */
    public function rawJson(): mixed
    {
        if ($this->raw === null || trim($this->raw) === '') {
            return null;
        }
        try {
            return Json::decode($this->raw);
        } catch (\JsonException) {
            return null;
        }
    }
}
