<?php

/**
 * Shared setup of the examples: a client from the environment.
 *
 * FINLIGHT_API_KEY is required; FINLIGHT_BASE_URL and FINLIGHT_WSS_URL are optional.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Finlight\FinlightClient;
use Finlight\FinlightConfig;

function example_config(): FinlightConfig
{
    $key = getenv('FINLIGHT_API_KEY');
    if (!is_string($key) || $key === '') {
        fwrite(STDERR, "Set FINLIGHT_API_KEY first.\n");
        exit(2);
    }

    return new FinlightConfig(
        apiKey: $key,
        baseUrl: getenv('FINLIGHT_BASE_URL') ?: FinlightConfig::DEFAULT_BASE_URL,
        wssUrl: getenv('FINLIGHT_WSS_URL') ?: FinlightConfig::DEFAULT_WSS_URL,
    );
}

function example_client(): FinlightClient
{
    return new FinlightClient(example_config());
}
