<?php

declare(strict_types=1);

namespace Finlight\Http;

use Finlight\Config;
use Finlight\Exception\ApiException;
use Finlight\Exception\AuthenticationException;
use Finlight\Exception\ConfigurationException;
use Finlight\Exception\NotFoundException;
use Finlight\Exception\RateLimitException;
use Finlight\Exception\TransportException;
use Finlight\FinlightClient;
use Http\Discovery\Exception as DiscoveryException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Authentication, JSON encoding, retries and error mapping.
 */
final class ApiClient
{
    /** @var list<int> */
    public const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    private const BACKOFF_BASE_MS = 500;
    private const BACKOFF_MAX_MS = 30_000;

    private ClientInterface $httpClient;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param (callable(int): void)|null $sleeper Receives microseconds. Test seam.
     */
    public function __construct(
        private readonly Config $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?callable $sleeper = null,
    ) {
        $this->httpClient = $httpClient ?? self::discover(
            static fn (): ClientInterface => self::createDefaultHttpClient($config),
            'PSR-18 HTTP client'
        );
        $this->requestFactory = $requestFactory ?? self::discover(
            static fn (): RequestFactoryInterface => Psr17FactoryDiscovery::findRequestFactory(),
            'PSR-17 request factory'
        );
        $this->streamFactory = $streamFactory ?? self::discover(
            static fn (): StreamFactoryInterface => Psr17FactoryDiscovery::findStreamFactory(),
            'PSR-17 stream factory'
        );
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * @param 'GET'|'POST'         $method
     * @param array<string, mixed> $data   Query string for GET, JSON body for POST.
     *
     * @return array<array-key, mixed>
     */
    public function request(string $method, string $path, array $data = []): array
    {
        $maxAttempts = $this->config->retryCount;
        $attempts = 0;

        while (true) {
            try {
                return $this->send($method, $path, $data);
            } catch (\Throwable $error) {
                ++$attempts;

                if ($attempts >= $maxAttempts || !self::isRetryable($error)) {
                    throw $error;
                }

                ($this->sleeper)(self::backoffMilliseconds($attempts) * 1000);
            }
        }
    }

    /**
     * @param 'GET'|'POST'         $method
     * @param array<string, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function send(string $method, string $path, array $data): array
    {
        $uri = rtrim($this->config->baseUrl, '/') . $path;

        if ($method === 'GET' && $data !== []) {
            $uri .= '?' . self::buildQuery($data);
        }

        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('X-API-KEY', $this->config->apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'finlight-php/' . FinlightClient::VERSION);

        if ($method === 'POST') {
            $body = json_encode($data === [] ? new \stdClass() : $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            throw new TransportException(
                sprintf('finlight: request to %s failed: %s', $uri, $error->getMessage()),
                0,
                $error
            );
        }

        return $this->decode($response);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw self::toApiException($status, $body, $response);
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ApiException('finlight: could not decode the API response as JSON.', $status, $body, $error);
        }

        if (!is_array($decoded)) {
            throw new ApiException('finlight: expected a JSON object or array in the API response.', $status, $body);
        }

        return $decoded;
    }

    private static function toApiException(int $status, string $body, ResponseInterface $response): ApiException
    {
        $message = self::extractErrorMessage($body)
            ?? sprintf('finlight: the API responded with HTTP %d.', $status);

        return match (true) {
            $status === 401, $status === 403 => new AuthenticationException($message, $status, $body),
            $status === 404 => new NotFoundException($message, $status, $body),
            $status === 429 => new RateLimitException($message, $status, $body, self::retryAfter($response)),
            default => new ApiException($message, $status, $body),
        };
    }

    private static function extractErrorMessage(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return null;
        }

        foreach (['message', 'error', 'detail'] as $key) {
            $value = $decoded[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return 'finlight: ' . $value;
            }

            if (is_array($value) && $value !== []) {
                $parts = array_filter($value, 'is_string');

                if ($parts !== []) {
                    return 'finlight: ' . implode('; ', $parts);
                }
            }
        }

        return null;
    }

    /**
     * Handles both the delay-seconds and HTTP-date forms of Retry-After.
     */
    private static function retryAfter(ResponseInterface $response): ?int
    {
        $header = trim($response->getHeaderLine('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $header) === 1) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }

    private static function isRetryable(\Throwable $error): bool
    {
        return $error instanceof ApiException
            && in_array($error->statusCode, self::RETRYABLE_STATUS_CODES, true);
    }

    private static function backoffMilliseconds(int $attempt): int
    {
        // Clamp the exponent too, or 2 ** 62 overflows to float before min() runs.
        $doublings = min($attempt - 1, 20);

        return (int) min(self::BACKOFF_BASE_MS * (2 ** $doublings), self::BACKOFF_MAX_MS);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function buildQuery(array $params): string
    {
        $normalized = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalized[$key] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }

        return http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
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
        } catch (DiscoveryException $error) {
            throw new ConfigurationException(
                sprintf(
                    'finlight: no %s found. Install one, for example `composer require guzzlehttp/guzzle`, '
                    . 'or pass your own into the FinlightClient constructor.',
                    $what
                ),
                0,
                $error
            );
        }
    }

    private static function createDefaultHttpClient(Config $config): ClientInterface
    {
        // Preferred when installed: it is the only way to apply the configured
        // timeout, since PSR-18 has no portable setting for it.
        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client([
                'timeout' => $config->timeoutMs / 1000,
                'connect_timeout' => $config->timeoutMs / 1000,
            ]);
        }

        return Psr18ClientDiscovery::find();
    }
}
