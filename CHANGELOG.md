# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/callbk/finlight-client-php/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/callbk/finlight-client-php/releases/tag/v1.0.0
