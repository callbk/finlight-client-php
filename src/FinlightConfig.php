<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Secret;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Settings of a FinlightClient. All times are seconds as float.
 *
 * REST:
 * - timeout (30 s) applies to each attempt, totalTimeout (none) caps a whole call including retries
 *   and the burst wait. Both apply to the HTTP client the SDK creates itself (Guzzle when installed).
 *   With your own httpClient its own timeouts apply; totalTimeout is still checked between attempts.
 * - maxRetries (3) retries 5xx answers, network errors and timeouts with growing, jittered waits
 *   between retryBaseDelay (0.5 s) and retryMaxDelay (8 s).
 * - retryOnBurst "once" (default): a burst 429 waits Retry-After seconds (burstWait, 10 s, when the
 *   header is missing) and is tried once more. "off" throws at once. This retry does not count
 *   against maxRetries. A monthly 429 is never retried.
 * - maxPages (10) caps articles->searchAll(). maxResponseBytes (32 MiB) caps an answer.
 * - The SDK keeps no cache of company records; see companies->get().
 *
 * WebSocket: this SDK has no WebSocket transport. wssUrl and the stream values below are the
 * values to use in your own socket loop (see the README): ping every pingInterval (25 s), reconnect
 * when no pong came for watchdogTimeout (60 s), open a fresh connection after rotationInterval
 * (115 min), back off between reconnectBaseDelay (1 s) and reconnectMaxDelay (30 s) and start
 * over only after a pong or an article, wait longBackoff (60 s) after a refusal for the
 * connection limit (not for a refused rotation dial: close the old connection and dial again
 * once first), and retry every
 * quotaReconnectInterval (1 h) while quotaReconnect is true when the WebSocket quota is used up.
 *
 * Injection: httpClient (PSR-18) with requestFactory and streamFactory (PSR-17), logger (PSR-3,
 * silent by default) and clock (PSR-20). A clock that also has a sleep(float|int $seconds) method
 * (like Symfony's MockClock) is used for the waits between retries.
 *
 * $config->apiKey reads the key back. It is not stored as a property of the config, so var_dump(),
 * print_r(), var_export(), an (array) cast and json_encode() of the config never show it. A config
 * cannot be serialized.
 *
 * @property-read string $apiKey
 */
final class FinlightConfig implements \JsonSerializable
{
    public const DEFAULT_BASE_URL = 'https://api.finlight.me';
    public const DEFAULT_WSS_URL = 'wss://wss.finlight.me';
    public const RETRY_ON_BURST_ONCE = 'once';
    public const RETRY_ON_BURST_OFF = 'off';

    /** The API key, kept where no dump of the config reaches it; see __get(). */
    private readonly Secret $secret;

    public function __construct(
        #[\SensitiveParameter]
        string $apiKey,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly string $wssUrl = self::DEFAULT_WSS_URL,
        public readonly float $timeout = 30.0,
        public readonly ?float $totalTimeout = null,
        public readonly int $maxRetries = 3,
        public readonly float $retryBaseDelay = 0.5,
        public readonly float $retryMaxDelay = 8.0,
        public readonly string $retryOnBurst = self::RETRY_ON_BURST_ONCE,
        public readonly float $burstWait = 10.0,
        public readonly int $maxPages = 10,
        public readonly int $maxResponseBytes = 32 * 1024 * 1024,
        public readonly float $pingInterval = 25.0,
        public readonly float $watchdogTimeout = 60.0,
        public readonly float $rotationInterval = 115 * 60.0,
        public readonly float $reconnectBaseDelay = 1.0,
        public readonly float $reconnectMaxDelay = 30.0,
        public readonly float $longBackoff = 60.0,
        public readonly bool $quotaReconnect = true,
        public readonly float $quotaReconnectInterval = 3600.0,
        public readonly int $searchFailedThreshold = 5,
        public readonly int $dedupeCacheSize = 1000,
        public readonly int $maxMessageBytes = 32 * 1024 * 1024,
        public readonly float $connectTimeout = 30.0,
        public readonly ?LoggerInterface $logger = null,
        public readonly ?ClockInterface $clock = null,
        public readonly ?ClientInterface $httpClient = null,
        public readonly ?RequestFactoryInterface $requestFactory = null,
        public readonly ?StreamFactoryInterface $streamFactory = null,
    ) {
        if (trim($apiKey) === '') {
            throw new FinlightValidationException('finlight: apiKey must not be empty', 'apiKey');
        }
        $this->secret = new Secret($apiKey);
        foreach (['baseUrl' => $baseUrl, 'wssUrl' => $wssUrl] as $name => $url) {
            if (preg_match('#^[a-z][a-z0-9+.-]*://[^/\s]+#i', $url) !== 1) {
                throw new FinlightValidationException(sprintf('finlight: %s is not a URL', $name), $name);
            }
        }
        if (!in_array($retryOnBurst, [self::RETRY_ON_BURST_ONCE, self::RETRY_ON_BURST_OFF], true)) {
            throw new FinlightValidationException('finlight: retryOnBurst must be "once" or "off"', 'retryOnBurst');
        }
        $positive = [
            'timeout' => $timeout, 'retryMaxDelay' => $retryMaxDelay, 'pingInterval' => $pingInterval,
            'watchdogTimeout' => $watchdogTimeout, 'rotationInterval' => $rotationInterval,
            'reconnectMaxDelay' => $reconnectMaxDelay, 'quotaReconnectInterval' => $quotaReconnectInterval,
            'connectTimeout' => $connectTimeout,
        ];
        foreach ($positive as $name => $value) {
            if (!($value > 0)) {
                throw new FinlightValidationException(sprintf('finlight: %s must be greater than 0', $name), $name);
            }
        }
        if ($totalTimeout !== null && !($totalTimeout > 0)) {
            throw new FinlightValidationException('finlight: totalTimeout must be greater than 0', 'totalTimeout');
        }
        $nonNegative = [
            'retryBaseDelay' => $retryBaseDelay, 'burstWait' => $burstWait, 'reconnectBaseDelay' => $reconnectBaseDelay,
            'longBackoff' => $longBackoff, 'maxRetries' => $maxRetries, 'dedupeCacheSize' => $dedupeCacheSize,
        ];
        foreach ($nonNegative as $name => $value) {
            if ($value < 0) {
                throw new FinlightValidationException(sprintf('finlight: %s must not be negative', $name), $name);
            }
        }
        foreach (['maxPages' => $maxPages, 'searchFailedThreshold' => $searchFailedThreshold, 'maxResponseBytes' => $maxResponseBytes] as $name => $value) {
            if ($value < 1) {
                throw new FinlightValidationException(sprintf('finlight: %s must be at least 1', $name), $name);
            }
        }
        if ($maxMessageBytes < 16 * 1024 * 1024) {
            throw new FinlightValidationException('finlight: maxMessageBytes must be at least 16 MiB', 'maxMessageBytes');
        }
    }

    public function __get(string $name): string
    {
        if ($name === 'apiKey') {
            return $this->secret->reveal();
        }
        throw new \Error(sprintf('Undefined property: %s::$%s', self::class, $name));
    }

    public function __isset(string $name): bool
    {
        return $name === 'apiKey';
    }

    public function __set(string $name, mixed $value): void
    {
        throw new \Error(sprintf('Cannot modify readonly property %s::$%s', self::class, $name));
    }

    public function __unset(string $name): void
    {
        throw new \Error(sprintf('Cannot modify readonly property %s::$%s', self::class, $name));
    }

    /**
     * The settings as JSON, with the API key masked.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * The API key never shows in var_dump() or print_r().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => '***',
            'baseUrl' => $this->baseUrl,
            'wssUrl' => $this->wssUrl,
            'timeout' => $this->timeout,
            'totalTimeout' => $this->totalTimeout,
            'maxRetries' => $this->maxRetries,
            'retryOnBurst' => $this->retryOnBurst,
            'maxPages' => $this->maxPages,
        ];
    }
}
