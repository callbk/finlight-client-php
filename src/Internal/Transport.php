<?php

declare(strict_types=1);

namespace Finlight\Internal;

use Finlight\FinlightApiException;
use Finlight\FinlightClient;
use Finlight\FinlightConfig;
use Finlight\FinlightDecodeException;
use Finlight\FinlightNetworkException;
use Finlight\FinlightTimeoutException;
use Finlight\FinlightValidationException;
use Finlight\RateLimitedException;
use Finlight\RateLimitType;
use Http\Discovery\Exception as DiscoveryException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * REST transport: one X-Request-Id per logical call, an explicit retry loop, no hidden retries.
 *
 * @internal
 */
final class Transport
{
    private ClientInterface $http;

    private RequestFactoryInterface $requests;

    private StreamFactoryInterface $streams;

    private LoggerInterface $logger;

    /** Set when the SDK created a Guzzle client itself: per-attempt timeouts can then be applied. */
    private ?object $guzzle = null;

    public function __construct(private readonly FinlightConfig $config)
    {
        $this->logger = $config->logger ?? new NullLogger();
        $this->http = $config->httpClient ?? $this->createClient();
        $this->requests = $config->requestFactory ?? self::discover(
            static fn (): RequestFactoryInterface => Psr17FactoryDiscovery::findRequestFactory(),
            'PSR-17 request factory',
        );
        $this->streams = $config->streamFactory ?? self::discover(
            static fn (): StreamFactoryInterface => Psr17FactoryDiscovery::findStreamFactory(),
            'PSR-17 stream factory',
        );
    }

    /**
     * Dumps show the config (with the key masked), never the HTTP client or its requests.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['config' => $this->config];
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Encode query parameters once. Commas inside a value become %2C.
     *
     * @param list<array{string, string}> $params
     */
    public static function encodeQuery(array $params): string
    {
        $parts = [];
        foreach ($params as [$name, $value]) {
            $parts[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return implode('&', $parts);
    }

    /**
     * One logical call with retries. Throws FinlightApiException for answers outside $okStatuses.
     *
     * @param list<array{string, string}> $query
     * @param array<string, string>       $headers
     * @param list<int>                   $okStatuses
     */
    public function call(string $method, string $path, array $query = [], ?string $body = null, array $headers = [], array $okStatuses = [200]): HttpResponse
    {
        $cfg = $this->config;
        $url = rtrim($cfg->baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . self::encodeQuery($query);
        }
        $requestId = Uuid::v4();
        $request = $this->requests->createRequest($method, $url)
            ->withHeader('X-API-KEY', $cfg->apiKey)
            ->withHeader('User-Agent', 'finlight-client-php/' . FinlightClient::VERSION)
            ->withHeader('x-client-version', FinlightClient::VERSION)
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('Accept', 'application/json');
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($body));
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $start = $this->now();
        $deadline = $cfg->totalTimeout !== null ? $start + $cfg->totalTimeout : null;
        $attempt = 0;
        $burstUsed = false;
        while (true) {
            $timeout = $cfg->timeout;
            if ($deadline !== null) {
                $remaining = $deadline - $this->now();
                if ($remaining <= 0) {
                    throw new FinlightTimeoutException(sprintf('finlight: the call ran out of its total time (%g s), request id %s', $cfg->totalTimeout, $requestId));
                }
                $timeout = min($timeout, $remaining);
            }

            try {
                $response = $this->send($request, $timeout);
                $status = $response->getStatusCode();
                $this->logger->debug(sprintf('finlight: %s %s -> %d, request id %s', $method, $path, $status, $requestId));
                $text = $this->readBody($response);
                if (in_array($status, $okStatuses, true)) {
                    return new HttpResponse($status, $response, $text, $requestId);
                }
                $error = Problem::fromResponse($status, $response->getHeaderLine('Content-Type'), $text, $requestId, $this->retryAfter($response));
                if ($error instanceof RateLimitedException) {
                    if ($error->limitType() === RateLimitType::BURST && $cfg->retryOnBurst === FinlightConfig::RETRY_ON_BURST_ONCE && !$burstUsed) {
                        $wait = $error->retryAfter() ?? $cfg->burstWait;
                        if ($deadline !== null && $this->now() + $wait > $deadline) {
                            throw $error;
                        }
                        $this->logger->warning(sprintf('finlight: burst limit reached, retrying once in %.1f s (request id %s)', $wait, $requestId));
                        $burstUsed = true;
                        $this->sleep($wait);
                        continue;
                    }
                    throw $error;
                }
                if ($status < 500) {
                    throw $error;
                }
            } catch (FinlightNetworkException $networkError) {
                $error = $networkError;
                $this->logger->debug(sprintf('finlight: %s %s failed (%s), request id %s', $method, $path, $error->kind(), $requestId));
            }

            if ($attempt >= $cfg->maxRetries) {
                throw $error;
            }
            $delay = min($cfg->retryMaxDelay, $cfg->retryBaseDelay * (2 ** min($attempt, 30)));
            $delay *= 0.5 + 0.5 * (random_int(0, 1_000_000) / 1_000_000);
            ++$attempt;
            if ($deadline !== null && $this->now() + $delay > $deadline) {
                throw $error;
            }
            $this->logger->info(sprintf(
                'finlight: retry %d/%d in %.2f s after %s (request id %s)',
                $attempt,
                $cfg->maxRetries,
                $delay,
                $error instanceof FinlightApiException ? 'HTTP ' . $error->status() : $error->kind(),
                $requestId,
            ));
            $this->sleep($delay);
        }
    }

    private function send(#[\SensitiveParameter] RequestInterface $request, float $timeout): ResponseInterface
    {
        try {
            if ($this->guzzle instanceof \GuzzleHttp\Client) {
                return $this->guzzle->send($request, [
                    'timeout' => $timeout,
                    'connect_timeout' => min($timeout, $this->config->connectTimeout),
                ]);
            }

            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw self::wrap($e, $timeout);
        } catch (\Exception $e) { // @phpstan-ignore catch.neverThrown
            // A foreign client that does not follow PSR-18: still never leak its exception type.
            throw self::wrap($e, $timeout);
        }
    }

    private static function wrap(\Throwable $e, float $timeout): FinlightNetworkException
    {
        $text = strtolower($e->getMessage());
        if (str_contains($text, 'timed out') || str_contains($text, 'timeout') || str_contains($text, 'curl error 28')) {
            return new FinlightTimeoutException(sprintf('finlight: the request timed out after %g s', $timeout), $e);
        }

        return new FinlightNetworkException(sprintf('finlight: network error (%s)', (new \ReflectionClass($e))->getShortName()), $e);
    }

    private function readBody(ResponseInterface $response): string
    {
        $limit = $this->config->maxResponseBytes;
        $length = $response->getHeaderLine('Content-Length');
        if ($length !== '' && ctype_digit($length) && (int) $length > $limit) {
            throw new FinlightDecodeException(sprintf('finlight: the answer is larger than %d bytes', $limit));
        }
        $stream = $response->getBody();
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $out = '';
            while (!$stream->eof()) {
                $chunk = $stream->read(65536);
                if ($chunk === '') {
                    break;
                }
                $out .= $chunk;
                if (strlen($out) > $limit) {
                    throw new FinlightDecodeException(sprintf('finlight: the answer is larger than %d bytes', $limit));
                }
            }

            return $out;
        } catch (\RuntimeException $e) {
            if ($e instanceof FinlightDecodeException) {
                throw $e;
            }
            throw self::wrap($e, $this->config->timeout);
        }
    }

    /** Retry-After as seconds (number form or HTTP date), or null. */
    private function retryAfter(ResponseInterface $response): ?float
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            return (float) $value;
        }
        $when = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC7231, $value, new \DateTimeZone('UTC'));
        if ($when === false) {
            return null;
        }

        return max(0.0, (float) $when->format('U.u') - $this->now());
    }

    /** Seconds, from the configured clock when there is one. */
    private function now(): float
    {
        if ($this->config->clock !== null) {
            return (float) $this->config->clock->now()->format('U.u');
        }

        return microtime(true);
    }

    private function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $clock = $this->config->clock;
        if ($clock !== null && method_exists($clock, 'sleep')) {
            $clock->sleep($seconds);

            return;
        }
        usleep((int) round($seconds * 1_000_000));
    }

    private function createClient(): ClientInterface
    {
        // Guzzle is preferred when installed: it is the one way to apply the timeouts, since
        // PSR-18 has no portable setting for them. Redirects and hidden retries are off.
        if (class_exists(\GuzzleHttp\Client::class)) {
            $client = new \GuzzleHttp\Client([
                'timeout' => $this->config->timeout,
                'connect_timeout' => min($this->config->timeout, $this->config->connectTimeout),
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
            $this->guzzle = $client;

            return $client;
        }

        return self::discover(static fn (): ClientInterface => Psr18ClientDiscovery::find(), 'PSR-18 HTTP client');
    }

    /**
     * @template T of object
     *
     * @param callable(): T $factory
     *
     * @return T
     */
    private static function discover(callable $factory, string $what): object
    {
        try {
            return $factory();
        } catch (DiscoveryException $e) {
            throw new FinlightValidationException(sprintf(
                'finlight: no %s found. Install one (for example `composer require guzzlehttp/guzzle`) or pass your own in FinlightConfig.',
                $what,
            ));
        }
    }
}
