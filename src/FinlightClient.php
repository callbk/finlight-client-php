<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Http\ApiClient;
use Finlight\Service\ArticleService;
use Finlight\Service\SourceService;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Entry point for the finlight REST API.
 *
 * Webhook verification is stateless and lives on
 * {@see \Finlight\Service\WebhookService::constructEvent()}.
 *
 * @see https://docs.finlight.me
 */
final class FinlightClient
{
    public const VERSION = '1.0.1';

    public readonly ArticleService $articles;

    public readonly SourceService $sources;

    /**
     * @param Config|string $config A Config, or just the API key to use the defaults.
     */
    public function __construct(
        Config|string $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $resolvedConfig = is_string($config) ? new Config($config) : $config;

        $apiClient = new ApiClient($resolvedConfig, $httpClient, $requestFactory, $streamFactory);

        $this->articles = new ArticleService($apiClient);
        $this->sources = new SourceService($apiClient);
    }
}
