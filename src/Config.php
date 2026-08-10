<?php

declare(strict_types=1);

namespace Finlight;

final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.finlight.me';
    public const DEFAULT_TIMEOUT_MS = 5000;
    public const DEFAULT_RETRY_COUNT = 3;

    /**
     * @param int $timeoutMs  Applies to the HTTP client this library creates. If you inject your
     *                        own client, set the timeout there instead.
     * @param int $retryCount Total attempts including the first, so 3 means one call and two retries.
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        public readonly int $retryCount = self::DEFAULT_RETRY_COUNT,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('finlight: apiKey must not be empty.');
        }

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException(sprintf('finlight: baseUrl "%s" is not a valid URL.', $baseUrl));
        }

        if ($timeoutMs < 1) {
            throw new \InvalidArgumentException('finlight: timeoutMs must be at least 1.');
        }

        if ($retryCount < 1) {
            throw new \InvalidArgumentException('finlight: retryCount must be at least 1.');
        }
    }
}
