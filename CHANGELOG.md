# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Breaking release.** 2.x speaks only the finlight API v3. Stay on 1.x (`finlight/client:^1.0`) for API v2.

## [Unreleased]

## [2.0.0] - unreleased

**Breaking release.** 2.0.0 speaks only the finlight API v3 (REST `/v3`, WebSocket `payloadVersion` 3,
v3 webhooks). Code written for 1.x does not run unchanged. To stay on 1.x, require `finlight/client:^1.0`.
Migration guide: https://docs.finlight.me/migration/v2-to-v3

Contract kit: 3.0.0-rc.6.

### Removed

- Everything from API v1 and v2: `Config`, `ArticleService`, `SourceService`, `WebhookService`, the
  `Model\` and `Request\` classes, the backed enums (`ArticleCategory`, `OrderBy`, `SortOrder`) and the
  old exceptions (`ApiException`, `AuthenticationException`, `RateLimitException`, `TransportException`,
  `ConfigurationException`, `MalformedResponseException`).
- PHP 8.1.
- Contract kit 3.0.0-rc.6: the SDK no longer keeps its own company cache. Removed from the 2.0.0 previews:
  the `companyCacheSize` config key, `CacheInfo::$notModified` and the revalidation of kept records.
  `companies->get()` without `ifNoneMatch:` sends no `If-None-Match`; a 304 to it is an error.

### Added

- `FinlightClient` with `articles->search()`, `articles->searchAll()` (frozen request, `maxPages`,
  `truncated()`, `nextCursor()`), `articles->get()`, `articles->getByLink()`, `companies->search()`,
  `companies->getMany()`, `companies->get()` (`cacheInfo()` with ETag and Cache-Control; `ifNoneMatch:`
  for your own ETag, a 304 returns `null`) and `sources->list()`. The SDK keeps no cache: to save credits,
  keep the records yourself and do not call while they are fresh. Each lookup costs 1 credit, like an
  article request (`getMany()` once per request); a 304 and errors cost 1 credit too.
  Parameters are arrays with the API keys; unknown keys and wrong shapes are refused before sending.
- `sourceTypes` and `excludeSourceTypes` on `articles->search()`, `articles->searchAll()`,
  `Subscribe::enriched()` and `Subscribe::raw()`. Articles of unclassified sources never match `sourceTypes`
  and are kept by `excludeSourceTypes`. On streams the SDK does not check the values; an unknown one is a
  `VALIDATION_FAILED` from the server. Needs a server that knows these keys; an older one ignores them.
- `FinlightConfig` with every key of the shared config table, PSR-18/PSR-17 injection, a PSR-3 logger and a
  PSR-20 clock. `User-Agent: finlight-client-php/2.0.0` and `x-client-version` on every request.
- Tolerant models (`Article`, `Company`, `EventTag`, `CompanyRecord`, `Source`, ...) that keep the raw JSON
  (`rawJson()`), write back their wire form (`toWire()`), keep times as sent with strict `...Time()` parsers,
  and tell absent, `null` and `[]` apart (`contentPresent()`, `sentimentPresent()`, `companiesPresent()`,
  `eventsPresent()`). A broken entry of a list is skipped and kept in `skipped`.
- Open vocabularies as string constants (`EventType`, `CompanyRole`, `IncludeBlock`, ...). `SourceType` has
  `newswire`, `press_release`, `financial_media`, `general_media`, `aggregator`, `blog` and `government`;
  `source->type` and `Source::$type` are `null` when the source is not classified.
- `Subscribe::enriched()`, `Subscribe::raw()` and `Subscribe::dialHeaders()` to build WebSocket subscribe
  messages and dial headers for your own socket loop, and `Frame::decode()` for v3 frames and the v2
  control frames sent before the first subscribe. There is no WebSocket transport.
- `Webhooks::constructEvent()`, `Webhooks::constructEventFromValues()` and `Webhooks::verifySignature()`.
  Headers from `$_SERVER`, arrays, header bags or PSR-7. A correctly signed body that is not
  `payloadVersion` 3 returns `WrongPayloadVersion` (answer it with 2xx).
- Errors: `FinlightApiException` with one subclass per problem code, `FinlightValidationException`,
  `FinlightNetworkException`, `FinlightTimeoutException`, `FinlightDecodeException`,
  `WebhookVerificationException`, all behind the `FinlightException` interface with `kind()`.
- Retries: up to 3 for 5xx, network errors and timeouts; never for 400, 401, 403, 404 and a monthly 429,
  which fails at once, also inside `searchAll()`. A burst 429 waits `Retry-After` (10 s without it) and
  retries once; on by default, `retryOnBurst: 'off'` turns it off. `totalTimeout` caps a whole call.
- Conformance suite: every case of the shared API v3 contract kit runs in PHPUnit (`CONFORMANCE.md`).

### Requirements

- PHP 8.2+ (64-bit), `php-http/discovery` 1.19+, `psr/log` 1.1+, `psr/clock` 1.0+.

## [1.0.1] - 2026-08-11

### Fixed

- `ArticleService::fetchArticleByLink()` threw `MalformedResponseException`
  against the live API. `GET /v2/articles/by-link` wraps its payload in a
  `{"status": ..., "article": {...}}` envelope, which was passed to
  `Article::fromArray()` unopened. The envelope is now unwrapped; a bare
  article is still accepted, since an article has no `article` field of its
  own. The unit test had mocked the unwrapped shape, so the suite never
  caught it.

## [1.0.0] - 2026-08-10

Initial release. Surface mirrors the official TypeScript client where the two
overlap, so method names, defaults and retry behaviour match across SDKs.

### Added

- `FinlightClient` entry point, constructed from a `Config` or a bare API key.
- `ArticleService::fetchArticles()` — article search over `POST /v2/articles`
  with the full filter set (`GetArticlesParams`).
- `ArticleService::fetchArticleByLink()` — single-article lookup over
  `GET /v2/articles/by-link`.
- `SourceService::getSources()` — available sources over `GET /v2/sources`.
- `WebhookService::constructEvent()` — HMAC-SHA256 webhook verification with
  constant-time comparison and a five-minute replay window.
- Typed models: `Article`, `Company`, `Listing`, `Source`, `ArticleResponse`
  (countable and iterable), plus `ArticleCategory`, `OrderBy` and `SortOrder`
  enums.
- Exception hierarchy behind the `FinlightException` marker interface:
  `AuthenticationException`, `NotFoundException`, `RateLimitException`,
  `ApiException`, `TransportException`, `MalformedResponseException`,
  `WebhookVerificationException`, `ConfigurationException`.
- Automatic retries on HTTP 429/500/502/503/504 with exponential backoff,
  capped at 30 seconds per pause. `Retry-After` is read in both its
  delay-seconds and HTTP-date forms.
- PSR-18 / PSR-17 based transport; any HTTP client can be injected.

### Notes

- WebSocket streaming is deliberately out of scope for this client; use
  webhooks, or one of the SDKs built for long-lived connections.

[Unreleased]: https://github.com/callbk/finlight-client-php/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/callbk/finlight-client-php/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/callbk/finlight-client-php/releases/tag/v1.0.0
