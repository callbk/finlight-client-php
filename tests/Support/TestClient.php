<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

use Finlight\FinlightClient;
use Finlight\FinlightConfig;
use Finlight\Internal\Transport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;

/**
 * A client wired to the PSR-18 mock and the fake clock.
 */
final class TestClient
{
    public readonly MockHttpClient $http;

    public readonly FakeClock $clock;

    public readonly FinlightClient $client;

    public readonly FinlightConfig $config;

    /**
     * @param array<string, mixed> $options more FinlightConfig arguments
     */
    public function __construct(array $options = [], ?LoggerInterface $logger = null)
    {
        $this->http = new MockHttpClient();
        $this->clock = new FakeClock();
        $factory = new Psr17Factory();
        $args = $options + [
            'apiKey' => Kit::KEY,
            'baseUrl' => 'https://api.test.invalid',
            'httpClient' => $this->http,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'clock' => $this->clock,
            'logger' => $logger,
        ];
        // @phpstan-ignore argument.type (named arguments from an array)
        $this->config = new FinlightConfig(...$args);
        $this->client = new FinlightClient($this->config);
    }

    public function transport(): Transport
    {
        return new Transport($this->config);
    }
}
