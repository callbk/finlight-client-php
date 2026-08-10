# finlight PHP Client

[![CI](https://github.com/callbk/finlight-client-php/actions/workflows/ci.yml/badge.svg)](https://github.com/callbk/finlight-client-php/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/finlight/client)](https://packagist.org/packages/finlight/client)
[![PHP Version](https://img.shields.io/packagist/dependency-v/finlight/client/php)](https://packagist.org/packages/finlight/client)
[![License](https://img.shields.io/packagist/l/finlight/client)](LICENSE)

PHP client for the [finlight.me](https://finlight.me) financial news API. Search market news enriched with sentiment scores and entity tags (tickers, ISIN, OpenFIGI), and verify inbound finlight webhooks.

Full API reference: **[docs.finlight.me](https://docs.finlight.me)** · Get an API key: [app.finlight.me](https://app.finlight.me)

## Requirements

- PHP 8.1 or newer
- Any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client (Guzzle is used automatically when installed)

## Installation

```bash
composer require finlight/client
```

If your project has no PSR-18 client yet, add one:

```bash
composer require finlight/client guzzlehttp/guzzle
```

## Quick start

```php
use Finlight\FinlightClient;
use Finlight\Request\GetArticlesParams;

$client = new FinlightClient('your-api-key');

$response = $client->articles->fetchArticles(new GetArticlesParams(
    tickers: ['AAPL', 'NVDA'],
    countries: ['US'],
    pageSize: 10,
));

foreach ($response as $article) {
    printf(
        "%s  %s  [%s]\n",
        $article->publishDate->format('Y-m-d H:i'),
        $article->title,
        $article->sentiment ?? 'unscored',
    );
}
```

## Searching articles

Every filter is optional — pass only what you need, as named arguments.

```php
use Finlight\Model\ArticleCategory;
use Finlight\Model\OrderBy;
use Finlight\Model\SortOrder;
use Finlight\Request\GetArticlesParams;

$response = $client->articles->fetchArticles(new GetArticlesParams(
    query: '(ticker:AAPL OR ticker:NVDA) AND NOT source:www.reuters.com AND "earnings"',
    from: '2026-01-01',
    to: '2026-06-30',
    language: 'en',
    categories: [ArticleCategory::Markets, ArticleCategory::Economy],
    includeContent: true,
    includeEntities: true,
    orderBy: OrderBy::PublishDate,
    order: SortOrder::Desc,
    pageSize: 50,
    page: 1,
));

echo $response->page, '/', $response->pageSize, ' — ', count($response), " articles\n";
```

| Parameter | Type | Notes |
| --- | --- | --- |
| `query` | `string` | Boolean operators, field filters (`ticker:`, `source:`, `isin:`) and quoted phrases |
| `tickers` | `string[]` | e.g. `['AAPL', 'NVDA']` |
| `sources` | `string[]` | Replaces the default source set |
| `optInSources` | `string[]` | Added on top of the default set |
| `excludeSources` | `string[]` | Removed from results |
| `countries` | `string[]` | ISO 3166-1 alpha-2 |
| `categories` | `ArticleCategory[]` | Topic filter |
| `from` / `to` | `string` | `YYYY-MM-DD` or ISO 8601 |
| `language` | `string` | ISO 639-1, defaults to `en` server-side |
| `includeContent` | `bool` | Full article body |
| `includeEntities` | `bool` | Tagged company data |
| `excludeEmptyContent` | `bool` | Skip articles without content |
| `orderBy` / `order` | `OrderBy` / `SortOrder` | Sorting |
| `page` / `pageSize` | `int` | `pageSize` accepts 1–100 |

`ArticleResponse` is `Countable` and `IteratorAggregate`, so `count($response)` and `foreach ($response as $article)` both work. The raw list stays available as `$response->articles`.

### A single article by URL

```php
use Finlight\Request\GetArticleByLinkParams;

$article = $client->articles->fetchArticleByLink(new GetArticleByLinkParams(
    link: 'https://www.reuters.com/markets/example-article',
    includeContent: true,
    includeEntities: true,
));

foreach ($article->companies ?? [] as $company) {
    printf("%s (%s) — confidence %.2f\n", $company->name, $company->ticker, $company->confidence ?? 0.0);
}
```

## Sources

```php
$sources = $client->sources->getSources();

$defaults = array_filter($sources, static fn ($source) => $source->isDefaultSource);

echo count($defaults), ' of ', count($sources), " sources are on by default\n";
```

## Webhooks

finlight signs every delivery with HMAC-SHA256. `WebhookService` verifies the signature, enforces a five-minute replay window when a timestamp header is present, and hands back a parsed `Article`.

It is static — no client instance and no API key required.

> **The body must be the raw, unparsed request bytes.** Any middleware that decodes and re-encodes JSON before you read it will invalidate the signature.

### Plain PHP

```php
use Finlight\Exception\WebhookVerificationException;
use Finlight\Service\WebhookService;

try {
    $article = WebhookService::constructEvent(
        file_get_contents('php://input'),
        $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '',
        getenv('FINLIGHT_WEBHOOK_SECRET') ?: '',
        $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? null,
    );
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}

// Acknowledge fast, process asynchronously.
http_response_code(200);
```

### Laravel

```php
use Finlight\Exception\WebhookVerificationException;
use Finlight\Service\WebhookService;
use Illuminate\Http\Request;

Route::post('/webhooks/finlight', function (Request $request) {
    try {
        $article = WebhookService::constructEvent(
            $request->getContent(),
            $request->header('X-Webhook-Signature', ''),
            config('services.finlight.webhook_secret'),
            $request->header('X-Webhook-Timestamp'),
        );
    } catch (WebhookVerificationException) {
        abort(400);
    }

    ProcessFinlightArticle::dispatch($article->link);

    return response()->noContent();
});
```

### Symfony

```php
#[Route('/webhooks/finlight', methods: ['POST'])]
public function handle(Request $request): Response
{
    try {
        $article = WebhookService::constructEvent(
            $request->getContent(),
            $request->headers->get('X-Webhook-Signature', ''),
            $this->finlightWebhookSecret,
            $request->headers->get('X-Webhook-Timestamp'),
        );
    } catch (WebhookVerificationException) {
        return new Response(status: 400);
    }

    $this->bus->dispatch(new IngestArticle($article->link));

    return new Response(status: 204);
}
```

## Configuration

```php
use Finlight\Config;
use Finlight\FinlightClient;

$client = new FinlightClient(new Config(
    apiKey: 'your-api-key',
    baseUrl: 'https://api.finlight.me',
    timeoutMs: 5000,
    retryCount: 3,
));
```

| Option | Default | Meaning |
| --- | --- | --- |
| `apiKey` | — | Required. Sent as the `X-API-KEY` header |
| `baseUrl` | `https://api.finlight.me` | API base URL |
| `timeoutMs` | `5000` | Applies to the HTTP client this library creates for you |
| `retryCount` | `3` | **Total** attempts, so the default is one call plus two retries |

### Bringing your own HTTP client

Any PSR-18 client works. Inject it to control timeouts, proxies, middleware or logging:

```php
use Finlight\Config;
use Finlight\FinlightClient;
use GuzzleHttp\Client as Guzzle;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();

$client = new FinlightClient(
    new Config(apiKey: 'your-api-key'),
    new Guzzle(['timeout' => 10]),
    $factory,
    $factory,
);
```

When you inject a client, `timeoutMs` no longer applies — PSR-18 has no portable timeout setting, so configure it on your own client.

## Errors

Everything this library throws implements `Finlight\Exception\FinlightException`, so one catch block covers the lot.

| Exception | When |
| --- | --- |
| `AuthenticationException` | HTTP 401 / 403 — key invalid or plan lacks access |
| `NotFoundException` | HTTP 404 |
| `RateLimitException` | HTTP 429 after retries; carries `retryAfterSeconds` |
| `ApiException` | Any other non-2xx; carries `statusCode` and `responseBody` |
| `TransportException` | No response at all — DNS, connection, TLS, timeout |
| `MalformedResponseException` | A 2xx body missing a required field |
| `WebhookVerificationException` | Signature, timestamp or payload rejected |
| `ConfigurationException` | No PSR-18 client or PSR-17 factory available at construction |

```php
use Finlight\Exception\FinlightException;
use Finlight\Exception\RateLimitException;

try {
    $response = $client->articles->fetchArticles($params);
} catch (RateLimitException $e) {
    sleep($e->retryAfterSeconds ?? 60);
} catch (FinlightException $e) {
    logger()->error('finlight request failed', ['error' => $e->getMessage()]);
}
```

### Retries

HTTP `429`, `500`, `502`, `503` and `504` are retried automatically with exponential backoff (500 ms, 1 s, 2 s, …, capped at 30 s per pause), matching the official TypeScript client. Transport failures are **not** retried — they surface immediately as a `TransportException`.

## Scope

This client covers the REST API and webhook verification.

**WebSocket streaming is intentionally not included.** PHP's request-response model is a poor fit for a long-lived stream, and doing it properly would require ReactPHP or Ratchet plus a supervised worker process. If you need the live stream, use the TypeScript, Go, Python or .NET clients — see [docs.finlight.me](https://docs.finlight.me). For push delivery into a PHP application, webhooks are the supported path.

## Development

```bash
composer install
composer test          # PHPUnit
composer stan          # PHPStan at max level, checked against PHP 8.1–8.4
```

CI runs the suite against PHP 8.1 through 8.4.

## Releasing

The package is distributed through [Packagist](https://packagist.org/packages/finlight/client), which reads this repository directly — there is no upload step.

1. Bump `FinlightClient::VERSION` and update `CHANGELOG.md`.
2. Tag the release: `git tag v1.0.1 && git push --tags`.
3. Packagist picks up the tag automatically once the GitHub integration is enabled (Packagist → your profile → *Settings* → GitHub integration, or the package's *Update* button for a manual sync).

## License

MIT — see [LICENSE](LICENSE).
