# finlight client for PHP

*[English](README.md) | 简体中文 | [日本語](README.ja.md) | [한국어](README.ko.md)*

> 本译文依据 [README.md](README.md) 编写。两者不一致时，以英文版为准。

> **破坏性版本。** 2.0.0 版只支持 finlight API v3。为 1.x 编写的代码无法直接运行。如需继续使用 1.x（API v2），
> 请使用 `finlight/client:^1.0`。参见 [从 1.x 迁移](#migrating-from-1x)。

[finlight](https://finlight.me) 新闻 API 的 PHP 客户端：通过 REST 搜索和查询金融新闻、公司和来源，为您自己的
socket 循环构建并解析 WebSocket 消息，并验证 webhook。

## Versions

| SDK version | API version | Status |
|---|---|---|
| 2.x | v3（REST `/v3`、WebSocket `payloadVersion` 3、v3 webhook） | 当前版本 |
| 1.x | v2 | 不再发布新版本 |

SDK 版本与 API 版本相互独立。其他支持 API v3 的 finlight SDK：TypeScript 4.x、Python 3.x、Go 模块 `/v2`、
.NET 2.x、Java 1.x、Ruby 1.x、Rust 0.2。

## Requirements

- PHP 8.2 或更高版本，64 位
- 一个 [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP 客户端和 [PSR-17](https://www.php-fig.org/psr/psr-17/)
  工厂。如果安装了 Guzzle 7，会自动使用它；其他客户端通过 `php-http/discovery` 1.19+ 查找。
- `psr/log` 1.1+、`psr/clock` 1.0+、`psr/http-message` 1.1+

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

参数是使用 API 键名的数组（`eventTypes`、`orderBy` 等）。只发送您设置的键。未知的键会在发送任何内容之前抛出
`FinlightValidationException`。

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

`searchAll()` 会替您逐页获取。调用时它会检查并冻结请求，之后的页面只添加 `cursor`。获取 `maxPages` 页（默认 10）
后停止，因为每一页都会消耗配额。

```php
$result = $client->articles->searchAll(['query' => 'ticker:AAPL', 'limit' => 100], maxPages: 5);
foreach ($result as $article) {        // or: foreach ($result->pages() as $page)
    echo $article->id, ' ', $article->title, "\n";
}
if ($result->truncated()) {
    echo 'continue with cursor ', $result->nextCursor(), "\n";
}
```

结果只能遍历一次。错误的格式（例如 `'limit' => 0` 或 `'languages' => []`）会在发送前抛出
`FinlightValidationException`。词汇表的值不在本地检查，因此服务器的新值无需更新 SDK 即可使用。

## Look up articles, companies and sources

```php
$article = $client->articles->get('EU8JwpsZ8iJehuyPZjMqRz', include: ['content']);
$article = $client->articles->getByLink('https://www.reuters.com/...');

$companies = $client->companies->search('NVDA', mic: 'XNAS');   // at most 10, no pages
$companies = $client->companies->getMany([4821, 1001]);
$company = $client->companies->get(4821);                       // revalidated with its ETag next time
echo $company->cacheInfo()?->etag, "\n";

$sources = $client->sources->list();
```

- 未找到时抛出 `NotFoundException`，绝不会返回空结果。
- 在文章 id 出现之前编入索引的文章，按 id 查询会返回 404。请对它们使用 `getByLink()`。
- 查询需要消耗额度：每次成功的 `companies` 或 `sources` 调用从每月配额中扣除 1 个 credit（`getMany()` 每次请求只算一次）。
  304 重新验证和错误响应不收费。每月配额和突发限制同样适用，因此这里也可能出现 429。
- `companies` 调用需要 `entityAccess` 权限（否则抛出 `ForbiddenException`）。
- `companies->get()` 保留最近的 256 条记录，并用其 ETag 重新验证；304 响应返回保留的记录。传入 `ifNoneMatch:`
  可自行管理缓存：此时 304 返回 `null`。
- `CompanyList` 和 `SourceList` 支持 `foreach` 和 `count()`。无法读取的条目在 `skipped()` 中。

## Absent, null and empty

部分键取决于您的套餐或 `include`。它们有三种状态：

| 状态 | 含义 | 判断方法 |
|---|---|---|
| 缺失 | 未请求、不在套餐内或 raw 通道 | `contentPresent()` / `sentimentPresent()` 为 false；`companies`、`events` 为 `null` |
| `null` | 已请求且有权限，但没有值 | `contentPresent()` 为 true 且 `content` 为 `null` |
| `[]` | 已打标签，但没有结果 | `companiesPresent()` 为 true 且 `companies` 为 `[]` |

- `content` 和 `sentiment` 使用 `contentPresent()` 和 `sentimentPresent()`。
- `companies`、`events`、`events[].companyIds` 和 `Source::$hasContent` 缺失时为 `null`。`companiesPresent()` 和
  `eventsPresent()` 表达相同含义。只有当同一响应中有 `companies` 时才会发送 `companyIds`。
- `media`、`countries` 和 `categories` 缺失时为 `[]`。
- 时间保留发送时的字符串（`publishedAt`）。`publishedAtTime()` 及其他 `...Time()` 方法严格解析，无法读取时返回 `null`。
- 来自较新服务器的未知键会被忽略。`rawJson()` 返回收到的原始 JSON。
- 词汇值保持为普通字符串。请与常量比较（`EventType::MNA`、`CompanyRole::ACQUIRER` 等）。未知值按原样保留。

## Real-time streams

本 SDK 没有 WebSocket 传输层。它为您自己的 socket 循环（例如 `ratchet/pawl` 或 `amphp/websocket-client`）构建消息并解析帧：

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

- `Subscribe::raw([...])` 为 `wss://wss.finlight.me/raw` 构建消息：未经富化的文章，键为 `query`、`sources`、
  `excludeSources`、`sourceTypes`、`excludeSourceTypes`、`language`、`includeUpdates`。raw 没有 `include`。
- 两种流都接受 `sourceTypes` 和 `excludeSourceTypes`（`SourceType` 的值），与搜索相同。SDK 不检查这些值：
  遇到未知的值时，服务器发送 `VALIDATION_FAILED` 并关闭连接。
- `Frame::decode()` 读取 v3 帧，以及服务器在您首次订阅之前发送的 v2 控制帧。类型：`created`、`updated`、`other`
  （本 SDK 尚不认识的事件，跳过即可）、`admitted`、`pong`、`preempted`、`error`。
- 循环规则：每 25 秒发送 `{"action":"ping","t":<epoch ms>}`。60 秒内没有收到任何形式的 pong，则关闭并重连。115 分钟后
  打开新连接。每次重连后发送相同的订阅。按事件、`data.id` 和 `data` 的哈希去重。这些值在 `FinlightConfig` 中
  （`pingInterval`、`watchdogTimeout`、`rotationInterval`）。
- 到 115 分钟时，在旧连接仍然打开的情况下建立新连接并订阅，然后关闭旧连接。如果您的套餐已达到连接上限（例如上限为 1），
  旧连接仍被计入，新连接会被拒绝。如果这次连接被拒绝或失败（403、5xx、网络错误），请关闭旧连接，等待它的关闭回复（最多
  5 秒），然后立即重新连接。不要报告这次拒绝。只有这次连接也被拒绝时，才按下文所述等待。会永久停止的拒绝（见下文）在这里同样停止。
- 以下情况永久停止：连接 401、连接 403 "Your subscription does not include WebSockets"、`VALIDATION_FAILED`、原因为
  `connection_limit` 的 `connection.preempted`，以及关闭码 1008。其他关闭、5xx、`HANDSHAKE_FAILED` 和 `SEARCH_FAILED` 时以递增的等待
  重连；连接 403 "Too many concurrent connections" 或 429 后至少等待 60 秒。`PING_ON_DEFAULT_ROUTE`、`INTERNAL_ERROR`
  和未知代码只需记录日志。
- 递增的等待从 `reconnectBaseDelay`（1 秒）开始，每次翻倍，最多到 `reconnectMaxDelay`（30 秒），只有收到 pong 或文章时
  才重新开始。`connection.admitted` 不算：它在服务器检查订阅之前到达，所以 admitted 之后出现 `SEARCH_FAILED` 或关闭的循环
  仍然必须放慢。
- 如果您的 WebSocket 库提供被拒连接的 body，请读取它，并按开头比较 403 文本。没有可读的 body 时：401 永久停止；403 至少
  等待 60 秒并报告错误。
- `SEARCH_FAILED` 连续出现 5 次后才报告（`searchFailedThreshold`），期间继续重连。
- 每月 WebSocket 配额用完时（连接 403 "WebSocket quota exceeded"，或原因为 `quota_exceeded` 的
  `connection.preempted`），每小时重试一次，直到成功（`quotaReconnect`、`quotaReconnectInterval`）。
- 重连后不会自动补载。请用 `articles->searchAll()` 从您看到的最后一个 `availableAt`（raw：`firstSeenAt`）开始补齐。

需要了解的限制：

- 套餐的连接数上限由 enriched、raw 和旧版 SDK 连接共享。发送 `x-takeover: true`
  （`Subscribe::dialHeaders($key, takeover: true)`）可替换最旧的连接。
- 服务器会在 2 小时后关闭连接。
- 订阅后，服务器会立即发送一次最新的匹配文章（每次重连后也会）。它可能是旧文章。
- 超过 32,000 字节的实时帧会被服务器静默丢弃。请用 `articles->get()` 获取这些文章。
- `connection.admitted` 在服务器检查订阅之前到达，并不表示订阅已被接受。

## Webhooks

finlight 会对每个 webhook 签名。请在解析之前，用**原始**请求体进行验证：

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

- `$headers` 可以是 `$_SERVER`、`getallheaders()`、PSR-7 请求或框架的 header bag。名称匹配不区分大小写。
  `constructEventFromValues($rawBody, $signature, $timestamp, $secret)` 直接接收两个 header 值；`verifySignature()` 只做检查。
- 时间戳必须在双向 300 秒（`tolerance`）以内。
- 对于控制台的测试 webhook，`$result->isTest()` 为 true。它不受套餐限制。
- 绝不要返回 410：这会立即停用 webhook。连续 10 次非 2xx 响应也会停用它。未处理的 `WrongPayloadVersion` 会导致
  500，因此请处理它。

获取原始请求体：

| 框架 | 原始请求体 | Headers |
|---|---|---|
| 纯 PHP | `file_get_contents('php://input')` | `$_SERVER` |
| Laravel | `$request->getContent()` | `$request->headers->all()` |
| Symfony | `$request->getContent()` | `$request->headers->all()` |
| PSR-7（Slim、Mezzio） | `$request->getBody()` | `$request` |

将 webhook 切换到 v3：先部署本 SDK；对 `WrongPayloadVersion` 返回 2xx 并保留其 `raw`；然后在控制台将 webhook 切换到
`payloadVersion` 3；再等待约 2 小时，因为切换前排队的重试仍以旧格式到达。

## Errors and retries

SDK 抛出的每个异常都实现 `FinlightException`，并具有 `kind()`。

| 异常 | `kind()` | 何时 |
|---|---|---|
| `FinlightApiException` | `api` | API 返回错误。`status()`、`code()`、`type()`、`title()`、`detail()`、`requestId()`、`errors()`、`apiKind()`、`raw()`。每个代码一个子类：`ValidationFailedException`、`CursorInvalidException`、`CursorMismatchException`、`UnauthorizedException`、`ForbiddenException`、`NotFoundException`、`RateLimitedException`、`InternalServerException`。未知代码使用基类（`apiKind()` 为 `other`）。 |
| `RateLimitedException` | `api` | `limitType()` 为 `monthly`、`burst`、`unknown` 或更新的值；`retryAfter()` 以秒为单位。 |
| `FinlightValidationException` | `validation` | 请求在本地被拒绝（同时也是 `InvalidArgumentException`）。 |
| `FinlightNetworkException`、`FinlightTimeoutException` | `network`、`timeout` | 网络故障或超时。原始错误为 `getPrevious()`。 |
| `FinlightDecodeException` | `decode` | 响应、帧或 webhook 请求体无法读取。 |
| `WebhookVerificationException` | `webhook_verification` | webhook 验证失败（`reason()`）。 |

重试：5xx 响应、网络错误和超时最多重试 3 次，等待时间递增，搜索也包括在内。400、401、403、404 和每月 429 从不重试：
每月 429 会立即失败，`searchAll()` 中也是如此。突发 429 会等待 `Retry-After` 秒（没有该 header 时为 10 秒）后再试一次；
设置 `retryOnBurst: 'off'` 可关闭此行为。每次调用发送自己的 `X-Request-Id`，重试时保持不变。`totalTimeout` 限制整个调用
（包括重试和突发等待）的时长；在 PHP-FPM 下请使用它。

PHP 没有取消机制，无法从外部停止正在运行的调用。请改用 `totalTimeout` 限制时长。

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
    companyCacheSize: 256,                // 0 turns the company cache off
    httpClient: null,                     // your own PSR-18 client
));
```

| 键 | 默认值 | |
|---|---|---|
| `apiKey` | 必填 | |
| `baseUrl` | `https://api.finlight.me` | |
| `wssUrl` | `wss://wss.finlight.me` | raw：`/raw` |
| `timeout` | 30 秒 | 每次尝试 |
| `totalTimeout` | 无 | 每次调用 |
| `maxRetries` | 3 | |
| `retryBaseDelay`、`retryMaxDelay` | 0.5 秒、8 秒 | |
| `retryOnBurst`、`burstWait` | `once`、10 秒 | `burstWait` 仅在没有 `Retry-After` 时使用 |
| `maxPages` | 10 | |
| `maxResponseBytes` | 32 MiB | |
| `companyCacheSize` | 256 | |
| `pingInterval`、`watchdogTimeout`、`rotationInterval` | 25 秒、60 秒、115 分钟 | 用于您的 socket 循环 |
| `reconnectBaseDelay`、`reconnectMaxDelay`、`longBackoff` | 1 秒、30 秒、60 秒 | 用于您的 socket 循环 |
| `quotaReconnect`、`quotaReconnectInterval` | 开启、1 小时 | 用于您的 socket 循环 |
| `searchFailedThreshold`、`dedupeCacheSize` | 5、1000 | 用于您的 socket 循环 |
| `maxMessageBytes`、`connectTimeout` | 32 MiB、30 秒 | 用于您的 socket 循环 |
| `logger` | 静默 | PSR-3 |
| `clock` | 系统时钟 | PSR-20；带 `sleep()` 的时钟用于等待 |
| `httpClient`、`requestFactory`、`streamFactory` | Guzzle 或 discovery | PSR-18、PSR-17 |

所有时间均为 `float` 类型的秒数。使用您自己的 `httpClient` 时，其超时、代理和中间件生效；请不要添加重试中间件，因为
每次尝试都会消耗配额。`totalTimeout` 仍会在两次尝试之间检查。

## Logging

传入 PSR-3 `logger` 即可查看 SDK 的行为。不传时 SDK 保持静默。它会记录重试（info）、突发等待、跳过的条目和停止的
`searchAll()`（warning），以及每个请求及其 request id（debug）。消息以 `finlight:` 开头。API 密钥、webhook 密钥、
签名和请求体绝不会出现在日志或异常消息中，`var_dump()` 配置时密钥显示为 `***`。SDK 从不写入 stdout，也不安装任何处理器。

## Data notes

- 旧文章的 `publishedAt` 可能是 `"1970-01-01T00:00:00.000Z"`，按原样交付。
- 空文本以 `null` 返回（`summary`、`content`）。
- 在角色功能出现之前打标签的文章，`role` 和 `relevance` 为 `null`，`events` 为 `[]`。
- 对于在 id 出现之前编入索引的文章，`articles->get($id)` 返回 404。请使用 `getByLink()`。
- 旧文章的 `availableAt` 为 `null`，raw 流中始终为 `null`。
- `source->type` 和 `Source::$type` 取 `SourceType` 的值。来源未分类时为 `null`；在来源类型出现之前编入索引的文章，
  `source->type` 也为 `null`。在 `search()`、`searchAll()`、`Subscribe::enriched()` 和 `Subscribe::raw()` 中用
  `sourceTypes` 和 `excludeSourceTypes` 过滤；未分类的来源永远不匹配 `sourceTypes`，并会被 `excludeSourceTypes` 保留。
  v3 webhook 也接受这两个过滤条件，在控制台中设置。

## Migrating from 1.x

完整指南见 [docs.finlight.me](https://docs.finlight.me/migration/v2-to-v3)。简要对照：

| 1.x | 2.x |
|---|---|
| `new Config('key', timeoutMs: 5000)` | `new FinlightConfig(apiKey: 'key', timeout: 5.0)` |
| `$client->articles->fetchArticles(new GetArticlesParams(...))` | `$client->articles->search([...])`、`searchAll([...])` |
| `fetchArticleByLink(new GetArticleByLinkParams(...))` | `getByLink($link, include: [...])`、`get($id)` |
| `page` / `pageSize` | `limit` / `cursor` |
| `includeContent`、`includeEntities` | `'include' => ['content', 'companies', 'events']` |
| `OrderBy::PublishDate`、`SortOrder::Desc`（枚举） | `'orderBy' => 'publishedAt'`、`'order' => 'desc'`（字符串） |
| `language` | `languages`（列表） |
| `$article->publishDate`、`createdAt`、`revisedDate`（DateTime） | `publishedAt`、`firstSeenAt`、`revisedAt`（字符串）、`publishedAtTime()` |
| `$article->images` | `$article->media[]->url` |
| `$article->source`（字符串） | `$article->source->domain` |
| `$article->sentiment`、`confidence` | `$article->sentiment->label`、`->confidence` |
| `$client->sources->getSources()` | `$client->sources->list()` |
| `WebhookService::constructEvent($body, $sig, $secret, $ts)` | `Webhooks::constructEvent($body, $headers, $secret)` |
| webhook `isUpdate` | `$event->event === 'article.updated'` |
| `ApiException`、`AuthenticationException`、`RateLimitException`、`TransportException` | `FinlightApiException`、`UnauthorizedException` / `ForbiddenException`、`RateLimitedException`、`FinlightNetworkException` |

切换 webhook 分四步：部署 2.x；对 `WrongPayloadVersion` 返回 2xx 并保留 `raw`；在控制台将 webhook 切换到
`payloadVersion` 3；等待约 2 小时，直到旧的重试结束。

## Development

```bash
composer install
composer test                  # unit and conformance tests, no network
composer stan                  # PHPStan, level max
composer sync-conformance -- --from=<monorepo>/shared/contracts/api-v3
composer check                 # kit MANIFEST, case map, README skeleton, no v1/v2 leftovers
```

`CONFORMANCE.md` 将契约套件（`conformance/`，版本见 `CONTRACT_VERSION`，并以 `MANIFEST` 校验）中的每个用例映射到其测试。
实时测试只针对 dev 运行，需要 `FINLIGHT_API_KEY`、`FINLIGHT_BASE_URL` 和 `FINLIGHT_WSS_URL`：`php scripts/live-smoke.php`。
它们会拒绝生产环境主机。

## License

MIT
