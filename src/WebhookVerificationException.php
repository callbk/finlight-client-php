<?php

declare(strict_types=1);

namespace Finlight;

/**
 * A webhook request failed verification. reason() says why:
 * invalid_raw_body, invalid_secret, missing_signature, missing_timestamp, duplicate_header,
 * non_ascii_header, unsupported_signature_scheme, unparseable_timestamp, signature_mismatch,
 * timestamp_outside_tolerance. Answer it with 400, never with 410.
 */
final class WebhookVerificationException extends \RuntimeException implements FinlightException
{
    public const INVALID_RAW_BODY = 'invalid_raw_body';
    public const INVALID_SECRET = 'invalid_secret';
    public const MISSING_SIGNATURE = 'missing_signature';
    public const MISSING_TIMESTAMP = 'missing_timestamp';
    public const DUPLICATE_HEADER = 'duplicate_header';
    public const NON_ASCII_HEADER = 'non_ascii_header';
    public const UNSUPPORTED_SIGNATURE_SCHEME = 'unsupported_signature_scheme';
    public const UNPARSEABLE_TIMESTAMP = 'unparseable_timestamp';
    public const SIGNATURE_MISMATCH = 'signature_mismatch';
    public const TIMESTAMP_OUTSIDE_TOLERANCE = 'timestamp_outside_tolerance';

    public function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public function kind(): string
    {
        return FinlightException::KIND_WEBHOOK_VERIFICATION;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
