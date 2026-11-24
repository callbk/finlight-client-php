<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Transport;

/**
 * Client for the finlight API v3 (REST).
 *
 *     $client = new FinlightClient('YOUR_API_KEY');
 *     $page = $client->articles->search(['query' => 'ticker:NVDA', 'limit' => 10]);
 *
 * WebSocket: this SDK has no transport; Subscribe builds the subscribe message and Frame decodes
 * what the server sends. Webhooks: see Webhooks::constructEvent().
 */
final class FinlightClient
{
    /** The SDK version. The only source of the version number. */
    public const VERSION = '2.0.0';

    /** The payloadVersion this SDK speaks on WebSocket and webhooks. */
    public const PAYLOAD_VERSION = 3;

    public readonly ArticlesService $articles;

    public readonly CompaniesService $companies;

    public readonly SourcesService $sources;

    /**
     * @param FinlightConfig|string $config a FinlightConfig, or the API key to use the defaults
     */
    public function __construct(#[\SensitiveParameter] FinlightConfig|string $config)
    {
        if (\PHP_INT_SIZE !== 8) {
            throw new FinlightValidationException('finlight: a 64-bit PHP is required (ids and epoch milliseconds need 64-bit integers)');
        }
        $config = is_string($config) ? new FinlightConfig($config) : $config;
        $transport = new Transport($config);
        $this->articles = new ArticlesService($transport, $config->maxPages);
        $this->companies = new CompaniesService($transport);
        $this->sources = new SourcesService($transport);
    }
}
