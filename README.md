# finlight client for PHP

*English | [简体中文](README.zh-CN.md) | [日本語](README.ja.md) | [한국어](README.ko.md)*

> **Breaking release.** Version 2.0.0 speaks only the finlight API v3. Code written for 1.x does not run
> unchanged. To stay on 1.x (API v2), require `finlight/client:^1.0`. See [Migrating from 1.x](#migrating-from-1x).

PHP client for the [finlight](https://finlight.me) news API: search and look up financial news,
companies and sources over REST, build and read WebSocket messages for your own socket loop, and verify
webhooks.

## Versions

| SDK version | API version | Status |
|---|---|---|
| 2.x | v3 (REST `/v3`, WebSocket `payloadVersion` 3, v3 webhooks) | current |
| 1.x | v2 | no further releases |

SDK and API versions are independent. Other finlight SDKs that speak API v3: TypeScript 4.x, Python 3.x,
Go module `/v2`, .NET 2.x, Java 1.x, Ruby 1.x, Rust 0.2.

## Requirements

- PHP 8.2 or newer, 64-bit
- A [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client and [PSR-17](https://www.php-fig.org/psr/psr-17/)
  factories. Guzzle 7 is used automatically when installed; any other is found through
  `php-http/discovery` 1.19+.
- `psr/log` 1.1+, `psr/clock` 1.0+, `psr/http-message` 1.1+

## Installation

```bash
composer require finlight/client:^2.0 guzzlehttp/guzzle
```

## Quick start

```php
use Finlight\FinlightClient;

$client = new FinlightClient('YOUR_API_KEY');

$page = $client->articles->search(['query' => 'ticker:NVDA', 'limit' => 10]);
foreach ($page->articles as $article) {
    echo $article->publishedAt, '  ', $article->title, "\n";
}
```

Parameters are arrays with the keys of the API (`eventTypes`, `orderBy`, ...). Only the keys you set are
sent. An unknown key throws `FinlightValidationException` before anything is sent.

## Search articles

```php
$page = $client->articles->search([
    'query' => 'eventType:mna AND companyCountry:US',
    'languages' => ['en', 'de'],
    'from' => '2026-11-01',            // a string is sent as it is
    'to' => new DateTimeImmutable(),    // a DateTimeInterface is sent as UTC with milliseconds
    'sourceTypes' => ['newswire', 'financial_media'],
    'eventTypes' => ['mna'],
    'minRelevance' => 0.5,
    'orderBy' => 'availableAt',
    'include' => ['content', 'companies', 'events'],
    'limit' => 50,
]);
echo $page->pagination->hasMore ? 'more' : 'done', ' ', $page->pagination->nextCursor ?? '', "\n";
```

`searchAll()` walks the pages for you. It checks and freezes the request when you call it. Later pages
only add the `cursor`. It stops after `maxPages` pages (default 10), because every page costs quota.

```php
$result = $client->articles->searchAll(['query' => 'ticker:AAPL', 'limit' => 100], maxPages: 5);
foreach ($result as $article) {        // or: foreach ($result->pages() as $page)
    echo $article->id, ' ', $article->title, "\n";
}
if ($result->truncated()) {
    echo 'continue with cursor ', $result->nextCursor(), "\n";
}
```

A result can be iterated once. Wrong shapes (for example `'limit' => 0` or `'languages' => []`) throw
`FinlightValidationException` before anything is sent. Values of vocabularies are not checked locally, so
new server values work without an SDK update.

## Look up articles, companies and sources

```php
$article = $client->articles->get('EU8JwpsZ8iJehuyPZjMqRz', include: ['content']);
$article = $client->articles->getByLink('https://www.reuters.com/...');

$companies = $client->companies->search('NVDA', mic: 'XNAS');   // at most 10, no pages
$companies = $client->companies->getMany([4821, 1001]);
$company = $client->companies->get(4821);                       // every call is a request, no SDK cache
$etag = $company->cacheInfo()?->etag;                           // keep it with the record to cache yourself
$fresh = $client->companies->get(4821, ifNoneMatch: $etag);     // null on 304 ("not modified")

$sources = $client->sources->list();
```

- A miss throws `NotFoundException`. It is never an empty result.
- Articles indexed before article ids existed answer 404 by id. Use `getByLink()` for them.
- Lookups cost credits like article requests: each `companies` or `sources` call costs 1 credit from the
  monthly quota (`getMany()` once per request). A 304 and errors cost 1 credit too. The monthly
  quota and the burst limit apply, so a 429 is possible here too.
- `companies` calls need the `entityAccess` entitlement (`ForbiddenException` otherwise).
- The SDK keeps no cache of company records; every `companies->get()` is a request. The article payload
  already carries the main company fields. To save credits, keep the records yourself and do not call again
  while they are fresh (`cacheInfo()->cacheControl`, max-age). After that you may pass the ETag back as
  `ifNoneMatch:`: a 304 then returns `null`. A 304 costs 1 credit too.
- `CompanyList` and `SourceList` work with `foreach` and `count()`. Entries that could not be read are in
  `skipped()`.

## Absent, null and empty

Some keys depend on your plan or on `include`. They have three states:

| State | Meaning | How to tell |
|---|---|---|
| absent | not requested, not in your plan, or a raw channel | `contentPresent()` / `sentimentPresent()` is false; `companies`, `events` are `null` |
| `null` | requested and allowed, but there is no value | `contentPresent()` is true and `content` is `null` |
| `[]` | tagged, nothing found | `companiesPresent()` is true and `companies` is `[]` |

- `content` and `sentiment` use `contentPresent()` and `sentimentPresent()`.
- `companies`, `events`, `events[].companyIds` and `Source::$hasContent` are `null` when absent.
  `companiesPresent()` and `eventsPresent()` say the same. `companyIds` is only sent when `companies` is in
  the same answer.
- `media`, `countries` and `categories` are `[]` when missing.
- Times keep the string that was sent (`publishedAt`). `publishedAtTime()` and the other `...Time()`
  methods parse it strictly and return `null` for anything unreadable.
- Unknown keys from newer servers are ignored. `rawJson()` gives the JSON as received.
- Vocabulary values stay plain strings. Compare them with the constants (`EventType::MNA`,
  `CompanyRole::ACQUIRER`, ...). Unknown values are kept as sent.

## Real-time streams

This SDK has no WebSocket transport. It builds the messages and decodes the frames for your own socket
loop (for example with `ratchet/pawl` or `amphp/websocket-client`):

```php
use Finlight\FinlightApiException;
use Finlight\Frame;
use Finlight\Subscribe;

$headers = Subscribe::dialHeaders('YOUR_API_KEY');   // lowercase x-api-key; never put the key in the URL
$subscribe = Subscribe::enriched([
    'query' => 'ticker:NVDA',
    'include' => ['companies', 'events'],
    'includeUpdates' => true,
]);
// dial wss://wss.finlight.me with $headers, then send $subscribe

$frame = Frame::decode($text);                       // for every text frame you receive
switch ($frame->kind()) {
    case Frame::CREATED:
    case Frame::UPDATED:
        echo $frame->event()?->data?->title, "\n";
        break;
    case Frame::PREEMPTED:
        echo $frame->preempted()?->reason, "\n";     // connection_limit: stop; quota_exceeded: wait 1 h
        break;
    case Frame::ERROR:
        echo $frame->error()?->code(), "\n";         // VALIDATION_FAILED: stop
        break;
}
```

- `Subscribe::raw([...])` builds the message for `wss://wss.finlight.me/raw`: articles before enrichment, keys
  `query`, `sources`, `excludeSources`, `sourceTypes`, `excludeSourceTypes`, `language`, `includeUpdates`.
  There is no `include` on raw.
- Both streams take `sourceTypes` and `excludeSourceTypes` (`SourceType` values), like search. The SDK does not
  check the values: for an unknown one the server sends `VALIDATION_FAILED` and closes the connection.
- `Frame::decode()` reads v3 frames and the v2 control frames the server sends before your first subscribe.
  Kinds: `created`, `updated`, `other` (an event this SDK does not know, skip it), `admitted`, `pong`,
  `preempted`, `error`.
- Rules for your loop. Send `{"action":"ping","t":<epoch ms>}` every 25 s. With no pong (either form) for
  60 s, close and reconnect. Open a fresh connection after 115 minutes. After every reconnect send the same
  subscribe. Drop duplicates by event, `data.id` and a hash of `data`. These values are in `FinlightConfig`
  (`pingInterval`, `watchdogTimeout`, `rotationInterval`).
- At 115 minutes, dial the new connection while the old one is still open, subscribe, then close the old
  one. When your plan is at its connection limit (for example a limit of 1), the old connection still counts
  and the new dial is refused. If that dial is refused or fails (403, 5xx, a network error), close the old
  connection, wait for its close reply (at most 5 s) and dial again at once. Do not report that refusal.
  Only if this dial is refused too, wait as described below. Refusals that stop for good (below) stop here too.
- Stop for good on: dial 401, dial 403 "Your subscription does not include WebSockets", `VALIDATION_FAILED`,
  `connection.preempted` with reason `connection_limit`, and a close with code 1008. Reconnect with a growing
  wait on other closes, 5xx, `HANDSHAKE_FAILED` and `SEARCH_FAILED`; wait at least 60 s after dial 403 "Too
  many concurrent connections" or 429. Only log `PING_ON_DEFAULT_ROUTE`, `INTERNAL_ERROR` and unknown codes.
- The growing wait starts at `reconnectBaseDelay` (1 s), doubles up to `reconnectMaxDelay` (30 s), and starts
  over only when a pong or an article arrives. `connection.admitted` does not count: it comes before the
  server checks the subscribe, so a loop of admitted, then `SEARCH_FAILED` or a close, must still slow down.
- Read the body of a refused dial when your WebSocket library gives it to you, and compare the 403 texts by
  their start. Without a readable body: 401 stops for good; 403 waits at least 60 s and reports an error.
- Report `SEARCH_FAILED` only after 5 in a row (`searchFailedThreshold`); keep reconnecting meanwhile.
- When the monthly WebSocket quota is used up (dial 403 "WebSocket quota exceeded", or `connection.preempted`
  with reason `quota_exceeded`), try again once per hour until it works (`quotaReconnect`,
  `quotaReconnectInterval`).
- Nothing is loaded for you after a reconnect. Fill gaps with `articles->searchAll()` from the last
  `availableAt` you saw (raw: `firstSeenAt`).

Limits to know:

- Your plan's connection limit is shared by enriched, raw and older SDK connections. Send `x-takeover: true`
  (`Subscribe::dialHeaders($key, takeover: true)`) to replace your oldest connection.
- The server closes connections after 2 hours.
- Right after subscribing, the server sends the newest matching article once (also after every reconnect).
  It can be old.
- Live frames over 32,000 bytes are dropped by the server without notice. Fetch those with `articles->get()`.
- `connection.admitted` arrives before the server checks the subscribe. It does not mean it was accepted.

## Webhooks

finlight signs every webhook. Verify it with the **raw** request body, before parsing anything:

```php
use Finlight\WebhookVerificationException;
use Finlight\Webhooks;
use Finlight\WrongPayloadVersion;

$rawBody = file_get_contents('php://input');
$secret = getenv('FINLIGHT_WEBHOOK_SECRET');
if (!is_string($secret) || $secret === '') {
    throw new RuntimeException('FINLIGHT_WEBHOOK_SECRET is not set');
}
try {
    $result = Webhooks::constructEvent($rawBody, $_SERVER, $secret);
} catch (WebhookVerificationException $e) {
    http_response_code(400);                 // never 410
    exit;
}
if ($result instanceof WrongPayloadVersion) {
    keep_for_later($result->raw);            // an old v2 body: answer 2xx anyway
    http_response_code(204);
    exit;
}
if (already_seen($result->eventId)) {         // deliveries can repeat: deduplicate on eventId
    http_response_code(204);
    exit;
}
http_response_code(204);                      // answer within 5 s, work afterwards
fastcgi_finish_request();
process($result->data);
```

- `$headers` can be `$_SERVER`, `getallheaders()`, a PSR-7 request, or a framework header bag. Names are
  matched without regard to case. `constructEventFromValues($rawBody, $signature, $timestamp, $secret)`
  takes the two header values; `verifySignature()` only checks.
- The timestamp must be within 300 s (`tolerance`) in both directions.
- `$result->isTest()` is true for the dashboard test webhook. It is not cut by your plan.
- Never answer with 410: it disables the webhook at once. Ten non-2xx answers in a row disable it too. A
  `WrongPayloadVersion` you do not handle ends in a 500, so handle it.

Getting the raw body:

| Framework | Raw body | Headers |
|---|---|---|
| Plain PHP | `file_get_contents('php://input')` | `$_SERVER` |
| Laravel | `$request->getContent()` | `$request->headers->all()` |
| Symfony | `$request->getContent()` | `$request->headers->all()` |
| PSR-7 (Slim, Mezzio) | `$request->getBody()` | `$request` |

Switching a webhook to v3: deploy this SDK first; answer `WrongPayloadVersion` with 2xx and keep its `raw`;
then switch the webhook to `payloadVersion` 3 in the dashboard; then wait about 2 hours, because retries
queued before the switch still arrive in the old format.

## Errors and retries

Every exception the SDK throws implements `FinlightException` and has `kind()`.

| Exception | `kind()` | When |
|---|---|---|
| `FinlightApiException` | `api` | The API answered with an error. `status()`, `code()`, `type()`, `title()`, `detail()`, `requestId()`, `errors()`, `apiKind()`, `raw()`. One subclass per code: `ValidationFailedException`, `CursorInvalidException`, `CursorMismatchException`, `UnauthorizedException`, `ForbiddenException`, `NotFoundException`, `RateLimitedException`, `InternalServerException`. Unknown codes stay on the base class (`apiKind()` `other`). |
| `RateLimitedException` | `api` | `limitType()` is `monthly`, `burst`, `unknown` or a newer value; `retryAfter()` in seconds. |
| `FinlightValidationException` | `validation` | A request was rejected locally (also an `InvalidArgumentException`). |
| `FinlightNetworkException`, `FinlightTimeoutException` | `network`, `timeout` | Network failure or timeout. The original error is `getPrevious()`. |
| `FinlightDecodeException` | `decode` | An answer, frame or webhook body could not be read. |
| `WebhookVerificationException` | `webhook_verification` | A webhook failed verification (`reason()`). |

Retries: 5xx answers, network errors and timeouts are retried up to 3 times with growing waits, the
search included. 400, 401, 403, 404 and a monthly 429 are never retried: a monthly 429 fails at once, also
inside `searchAll()`. A burst 429 waits `Retry-After` seconds (10 without the header) and tries once more;
set `retryOnBurst: 'off'` to turn that off. Every call sends its own `X-Request-Id`, the same on each retry.
`totalTimeout` caps a whole call, retries and the burst wait included; use it under PHP-FPM.

PHP has no cancellation, so a running call cannot be stopped from outside. Use `totalTimeout` to bound it.

## Configuration

```php
use Finlight\FinlightClient;
use Finlight\FinlightConfig;

$client = new FinlightClient(new FinlightConfig(
    apiKey: 'YOUR_API_KEY',
    baseUrl: 'https://api.finlight.me',   // a trailing slash is fine
    wssUrl: 'wss://wss.finlight.me',      // raw streams use <wssUrl>/raw
    timeout: 30.0,                        // seconds per attempt
    totalTimeout: null,                   // optional cap per call, retries included
    maxRetries: 3,
    retryOnBurst: 'once',                 // or 'off'
    maxPages: 10,
    httpClient: null,                     // your own PSR-18 client
));
```

| Key | Default | |
|---|---|---|
| `apiKey` | required | |
| `baseUrl` | `https://api.finlight.me` | |
| `wssUrl` | `wss://wss.finlight.me` | raw: `/raw` |
| `timeout` | 30 s | per attempt |
| `totalTimeout` | none | per call |
| `maxRetries` | 3 | |
| `retryBaseDelay`, `retryMaxDelay` | 0.5 s, 8 s | |
| `retryOnBurst`, `burstWait` | `once`, 10 s | `burstWait` only without `Retry-After` |
| `maxPages` | 10 | |
| `maxResponseBytes` | 32 MiB | |
| `pingInterval`, `watchdogTimeout`, `rotationInterval` | 25 s, 60 s, 115 min | for your socket loop |
| `reconnectBaseDelay`, `reconnectMaxDelay`, `longBackoff` | 1 s, 30 s, 60 s | for your socket loop |
| `quotaReconnect`, `quotaReconnectInterval` | on, 1 h | for your socket loop |
| `searchFailedThreshold`, `dedupeCacheSize` | 5, 1000 | for your socket loop |
| `maxMessageBytes`, `connectTimeout` | 32 MiB, 30 s | for your socket loop |
| `logger` | silent | PSR-3 |
| `clock` | system clock | PSR-20; a clock with `sleep()` is used for the waits |
| `httpClient`, `requestFactory`, `streamFactory` | Guzzle or discovery | PSR-18, PSR-17 |

All times are seconds as `float`. With your own `httpClient`, its timeouts, proxies and middleware apply;
do not add retry middleware, because every attempt costs quota. `totalTimeout` is still checked between
attempts.

## Logging

Pass a PSR-3 `logger` to see what the SDK does. Without one it is silent. It logs retries (info), the burst
wait, skipped entries and a stopped `searchAll()` (warning), and each request with its request id (debug).
Messages start with `finlight:`. It never logs or puts into exception messages the API key, the webhook
secret, signatures or bodies, and `var_dump()` of the config shows the key as `***`. The SDK never writes
to stdout and installs no handlers.

## Data notes

- Old articles can carry `publishedAt` `"1970-01-01T00:00:00.000Z"`. It is delivered as sent.
- Empty texts come as `null` (`summary`, `content`).
- Articles tagged before roles existed have `role` and `relevance` `null` and `events` `[]`.
- `articles->get($id)` answers 404 for articles indexed before ids existed. Use `getByLink()`.
- `availableAt` is `null` on older articles and always `null` on raw streams.
- `source->type` and `Source::$type` take the `SourceType` values. They are `null` when the source is not
  classified, and `source->type` is also `null` on articles indexed before source types existed. Filter with
  `sourceTypes` and `excludeSourceTypes` in `search()`, `searchAll()`, `Subscribe::enriched()` and
  `Subscribe::raw()`; unclassified sources never match `sourceTypes` and are kept by `excludeSourceTypes`.
  v3 webhooks take the same two filters; you set them in the dashboard.

## Migrating from 1.x

The full guide is on [docs.finlight.me](https://docs.finlight.me/migration/v2-to-v3). The short version:

| 1.x | 2.x |
|---|---|
| `new Config('key', timeoutMs: 5000)` | `new FinlightConfig(apiKey: 'key', timeout: 5.0)` |
| `$client->articles->fetchArticles(new GetArticlesParams(...))` | `$client->articles->search([...])`, `searchAll([...])` |
| `fetchArticleByLink(new GetArticleByLinkParams(...))` | `getByLink($link, include: [...])`, `get($id)` |
| `page` / `pageSize` | `limit` / `cursor` |
| `includeContent`, `includeEntities` | `'include' => ['content', 'companies', 'events']` |
| `OrderBy::PublishDate`, `SortOrder::Desc` (enums) | `'orderBy' => 'publishedAt'`, `'order' => 'desc'` (strings) |
| `language` | `languages` (a list) |
| `$article->publishDate`, `createdAt`, `revisedDate` (DateTime) | `publishedAt`, `firstSeenAt`, `revisedAt` (strings), `publishedAtTime()` |
| `$article->images` | `$article->media[]->url` |
| `$article->source` (string) | `$article->source->domain` |
| `$article->sentiment`, `confidence` | `$article->sentiment->label`, `->confidence` |
| `$client->sources->getSources()` | `$client->sources->list()` |
| `WebhookService::constructEvent($body, $sig, $secret, $ts)` | `Webhooks::constructEvent($body, $headers, $secret)` |
| webhook `isUpdate` | `$event->event === 'article.updated'` |
| `ApiException`, `AuthenticationException`, `RateLimitException`, `TransportException` | `FinlightApiException`, `UnauthorizedException` / `ForbiddenException`, `RateLimitedException`, `FinlightNetworkException` |

Switching your webhook takes four steps: deploy 2.x; answer `WrongPayloadVersion` with 2xx and keep `raw`;
switch the webhook to `payloadVersion` 3 in the dashboard; wait about 2 hours for old retries to end.

## Development

```bash
composer install
composer test                  # unit and conformance tests, no network
composer stan                  # PHPStan, level max
composer sync-conformance -- --from=<monorepo>/shared/contracts/api-v3
composer check                 # kit MANIFEST, case map, README skeleton, no v1/v2 leftovers
```

`CONFORMANCE.md` maps every case of the contract kit (`conformance/`, version in `CONTRACT_VERSION`,
checked against `MANIFEST`) to its test. Live tests run only against dev and need `FINLIGHT_API_KEY`,
`FINLIGHT_BASE_URL` and `FINLIGHT_WSS_URL`: `php scripts/live-smoke.php`. They refuse production hosts.

## License

MIT
