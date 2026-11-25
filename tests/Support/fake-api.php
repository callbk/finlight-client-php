<?php

/**
 * A fake finlight API for the example runs: `php -S 127.0.0.1:PORT tests/Support/fake-api.php`.
 * It answers from the contract kit fixtures and refuses requests without an X-API-KEY.
 */

declare(strict_types=1);

$kit = dirname(__DIR__, 2) . '/conformance/fixtures';

/**
 * @return array<mixed>
 */
$load = static function (string $rel) use ($kit): array {
    $data = json_decode((string) file_get_contents($kit . '/' . $rel), true, 512, JSON_THROW_ON_ERROR);

    return is_array($data) ? $data : [];
};
$send = static function (mixed $response): void {
    $response = is_array($response) ? $response : [];
    $status = $response['status'] ?? 200;
    http_response_code(is_int($status) ? $status : 200);
    $headers = $response['headers'] ?? [];
    foreach (is_array($headers) ? $headers : [] as $name => $value) {
        header($name . ': ' . (is_string($value) ? $value : ''));
    }
    $body = $response['body'] ?? null;
    if ($body !== null) {
        echo is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
};

$all = function_exists('getallheaders') ? getallheaders() : [];
$headers = is_array($all) ? array_change_key_case($all, CASE_LOWER) : [];
if (($headers['x-api-key'] ?? '') === '') {
    $send($load('errors/unauthorized-missing-key.json')['response'] ?? null);

    return true;
}

$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = (string) parse_url($uri, PHP_URL_PATH);
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

$route = match (true) {
    $method === 'POST' && $path === '/v3/articles/search' => 'search',
    $method === 'GET' && $path === '/v3/articles/by-link' => 'requests/get-by-link.json',
    $method === 'GET' && $path === '/v3/companies' => isset($query['ids']) ? 'requests/companies-get-many.json' : 'requests/companies-search.json',
    $method === 'GET' && preg_match('#^/v3/companies/\d+$#', $path) === 1 => 'requests/companies-get.json',
    $method === 'GET' && $path === '/v3/sources' => 'requests/sources-list-full.json',
    $method === 'GET' && $path === '/v3/articles/this-id-does-not-exist' => 'errors/not-found-id.json',
    $method === 'GET' && str_starts_with($path, '/v3/articles/') => 'requests/get-by-id.json',
    default => 'errors/unknown-route.json',
};

if ($route !== 'search') {
    $send($load($route)['response'] ?? null);

    return true;
}

// The three-page walk of the kit: the page is chosen by the cursor in the body.
$body = json_decode((string) file_get_contents('php://input'), true);
$cursor = is_array($body) ? ($body['cursor'] ?? null) : null;
$steps = $load('pagination/three-pages.json')['steps'] ?? [];
foreach (is_array($steps) ? $steps : [] as $step) {
    if (!is_array($step) || !is_array($step['request'] ?? null) || !is_array($step['request']['body'] ?? null)) {
        continue;
    }
    if (($step['request']['body']['cursor'] ?? null) === $cursor) {
        $send($step['response'] ?? null);

        return true;
    }
}
$send($load('errors/cursor-invalid.json')['response'] ?? null);

return true;
