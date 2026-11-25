<?php

declare(strict_types=1);

namespace Finlight;

/**
 * One entry of FinlightApiException::errors(): a field of the request and what is wrong with it.
 *
 * allowedValues is set for closed request vocabularies. constraints ({rule: text}) is only set on
 * errors of a WebSocket subscribe (connection.error details).
 */
final class FieldIssue
{
    /**
     * @param list<string>|null          $allowedValues
     * @param array<string, string>|null $constraints
     */
    public function __construct(
        public readonly ?string $field,
        public readonly ?string $issue,
        public readonly ?array $allowedValues = null,
        public readonly ?array $constraints = null,
    ) {
    }
}
