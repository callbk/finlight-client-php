<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Json;
use Finlight\Internal\Params;

/**
 * Builds the WebSocket subscribe message for your own socket loop. This SDK has no WebSocket
 * transport; see the README for the connection rules (ping, watchdog, rotation, reconnects).
 *
 *     $json = Subscribe::enriched(['query' => 'ticker:NVDA', 'include' => ['companies']]);
 *     // dial FinlightConfig::DEFAULT_WSS_URL with Subscribe::dialHeaders($apiKey), then send $json
 *
 * The message always carries payloadVersion as the JSON number 3 and a clientNonce. It is built
 * from a fixed list of keys; an unknown key (v2 keys too) throws FinlightValidationException
 * before anything is sent.
 */
final class Subscribe
{
    private function __construct()
    {
    }

    /**
     * Subscribe message for the enriched endpoint (wss://wss.finlight.me).
     *
     * Keys: include, query, sources, excludeSources, optInSources, language, tickers, countries,
     * categories, sourceTypes, excludeSourceTypes, excludeEmptyContent, includeUpdates. Without
     * includeUpdates no article.updated is ever delivered. sourceTypes and excludeSourceTypes take
     * SourceType values, like search; an unknown value is a VALIDATION_FAILED from the server.
     *
     * @param array<string, mixed> $params
     */
    public static function enriched(array $params, ?string $clientNonce = null): string
    {
        return self::frame(Params::build(Params::STREAM, $params, 'stream'), $clientNonce);
    }

    /**
     * Subscribe message for the raw endpoint (wss://wss.finlight.me/raw): articles before
     * enrichment. Keys: query, sources, excludeSources, sourceTypes, excludeSourceTypes, language,
     * includeUpdates. There is no include on raw.
     *
     * @param array<string, mixed> $params
     */
    public static function raw(array $params, ?string $clientNonce = null): string
    {
        return self::frame(Params::build(Params::RAW_STREAM, $params, 'raw stream'), $clientNonce);
    }

    /**
     * The headers to send when you open the WebSocket connection. Header names are lowercase on
     * purpose: the gateway looks up x-api-key case-sensitively. Never put the key into the URL.
     * Set $takeover to replace your oldest connection when the connection limit is reached.
     *
     * @return array<string, string>
     */
    public static function dialHeaders(#[\SensitiveParameter] string $apiKey, bool $takeover = false): array
    {
        if (trim($apiKey) === '') {
            throw new FinlightValidationException('finlight: apiKey must not be empty', 'apiKey');
        }
        $headers = [
            'x-api-key' => $apiKey,
            'x-client-version' => FinlightClient::VERSION,
            'user-agent' => 'finlight-client-php/' . FinlightClient::VERSION,
        ];
        if ($takeover) {
            $headers['x-takeover'] = 'true';
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function frame(array $body, ?string $clientNonce): string
    {
        $nonce = $clientNonce ?? 'php-' . bin2hex(random_bytes(8));
        if ($nonce === '') {
            throw new FinlightValidationException('finlight: clientNonce must not be empty', 'clientNonce');
        }
        Params::utf8('clientNonce', $nonce);
        $frame = ['payloadVersion' => FinlightClient::PAYLOAD_VERSION] + $body;
        $frame['clientNonce'] = $nonce;

        return Json::encodeRequest($frame);
    }
}
