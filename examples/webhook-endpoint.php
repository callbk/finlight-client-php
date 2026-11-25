<?php

/**
 * A minimal webhook endpoint. Run it with the built-in server:
 *
 *     FINLIGHT_WEBHOOK_SECRET=... php -S 0.0.0.0:8080 examples/webhook-endpoint.php
 *
 * It reads the raw body, verifies it, answers within 5 s with 2xx and works afterwards.
 * It never answers 410 (that disables the webhook at once).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Finlight\FinlightDecodeException;
use Finlight\WebhookVerificationException;
use Finlight\Webhooks;
use Finlight\WrongPayloadVersion;

$secret = getenv('FINLIGHT_WEBHOOK_SECRET');
if (!is_string($secret) || $secret === '') {
    http_response_code(500);
    error_log('Set FINLIGHT_WEBHOOK_SECRET first.');
    exit;
}

// The raw body, exactly as received. Never json_decode() and re-encode it before verifying.
$rawBody = (string) file_get_contents('php://input');

try {
    $result = Webhooks::constructEvent($rawBody, $_SERVER, $secret);
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    error_log('finlight webhook rejected: ' . $e->reason());
    exit;
} catch (FinlightDecodeException) {
    http_response_code(400);
    exit;
}

$store = sys_get_temp_dir() . '/finlight-webhook-example';
if (!is_dir($store)) {
    mkdir($store, 0700, true);
}

if ($result instanceof WrongPayloadVersion) {
    // An old v2 body (retries arrive for about 2 h after switching to v3): keep it, answer 2xx.
    file_put_contents($store . '/v2-' . sha1($rawBody) . '.json', json_encode($result->raw));
    http_response_code(204);
    exit;
}

// Deliveries can repeat: deduplicate on eventId.
$marker = $store . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $result->eventId);
if (!@mkdir($marker)) {
    http_response_code(204);
    error_log('finlight webhook duplicate: ' . $result->eventId);
    exit;
}

// Answer first, then do the slow work.
http_response_code(204);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
error_log(sprintf('finlight webhook %s %s: %s%s', $result->event, $result->eventId, $result->data->title, $result->isTest() ? ' (test)' : ''));
