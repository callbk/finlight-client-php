# finlight PHP Client

*[English](README.md) | 简体中文 | [日本語](README.ja.md) | [한국어](README.ko.md)*

[![CI](https://github.com/callbk/finlight-client-php/actions/workflows/ci.yml/badge.svg)](https://github.com/callbk/finlight-client-php/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/finlight/client)](https://packagist.org/packages/finlight/client)
[![PHP Version](https://img.shields.io/packagist/dependency-v/finlight/client/php)](https://packagist.org/packages/finlight/client)
[![License](https://img.shields.io/packagist/l/finlight/client)](LICENSE)

[finlight.me](https://finlight.me) 财经新闻 API 的 PHP 客户端。可检索带情感评分和实体标注（股票代码、ISIN、OpenFIGI）的市场新闻，并验证收到的 finlight Webhook。

完整 API 参考：**[docs.finlight.me](https://docs.finlight.me)** · 获取 API 密钥：[app.finlight.me](https://app.finlight.me)

## 环境要求

- PHP 8.1 或更高版本
- 任意 [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP 客户端（已安装 Guzzle 时会自动使用）

## 安装

```bash
composer require finlight/client
```

如果你的项目尚无 PSR-18 客户端，请一并安装：

```bash
composer require finlight/client guzzlehttp/guzzle
```

## 快速开始

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

## 检索文章

所有过滤条件均为可选 —— 按需以命名参数传入即可。

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

| 参数 | 类型 | 说明 |
| --- | --- | --- |
| `query` | `string` | 布尔运算符、字段过滤（`ticker:`、`source:`、`isin:`）和引号短语 |
| `tickers` | `string[]` | 例如 `['AAPL', 'NVDA']` |
| `sources` | `string[]` | 替换默认新闻源集合 |
| `optInSources` | `string[]` | 在默认集合之上追加 |
| `excludeSources` | `string[]` | 从结果中剔除 |
| `countries` | `string[]` | ISO 3166-1 alpha-2 |
| `categories` | `ArticleCategory[]` | 主题过滤 |
| `from` / `to` | `string` | `YYYY-MM-DD` 或 ISO 8601 |
| `language` | `string` | ISO 639-1，服务端默认为 `en` |
| `includeContent` | `bool` | 文章全文 |
| `includeEntities` | `bool` | 标注的公司数据 |
| `excludeEmptyContent` | `bool` | 跳过无正文的文章 |
| `orderBy` / `order` | `OrderBy` / `SortOrder` | 排序 |
| `page` / `pageSize` | `int` | `pageSize` 取值范围 1–100 |

`ArticleResponse` 实现了 `Countable` 和 `IteratorAggregate`，因此 `count($response)` 和 `foreach ($response as $article)` 均可使用。原始列表仍可通过 `$response->articles` 访问。

### 按 URL 获取单篇文章

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

## 新闻源

```php
$sources = $client->sources->getSources();

$defaults = array_filter($sources, static fn ($source) => $source->isDefaultSource);

echo count($defaults), ' of ', count($sources), " sources are on by default\n";
```

## Webhook

finlight 对每次投递都使用 HMAC-SHA256 签名。`WebhookService` 会验证签名，在存在时间戳请求头时强制执行五分钟重放窗口，并返回解析后的 `Article`。

它是静态方法 —— 无需客户端实例，也无需 API 密钥。

> **请求体必须是原始、未解析的字节。** 任何在你读取之前解码并重新编码 JSON 的中间件都会使签名失效。

### 原生 PHP

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

// 快速确认，异步处理。
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

## 配置

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

| 选项 | 默认值 | 含义 |
| --- | --- | --- |
| `apiKey` | — | 必填。通过 `X-API-KEY` 请求头发送 |
| `baseUrl` | `https://api.finlight.me` | API 基础地址 |
| `timeoutMs` | `5000` | 作用于本库为你创建的 HTTP 客户端 |
| `retryCount` | `3` | **总**尝试次数，因此默认为一次调用加两次重试 |

### 使用你自己的 HTTP 客户端

任意 PSR-18 客户端均可。注入它即可控制超时、代理、中间件或日志：

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

注入客户端后 `timeoutMs` 不再生效 —— PSR-18 没有可移植的超时设置，请在你自己的客户端上配置。

## 错误处理

本库抛出的所有异常都实现了 `Finlight\Exception\FinlightException`，因此一个 catch 块即可全部覆盖。

| 异常 | 触发时机 |
| --- | --- |
| `AuthenticationException` | HTTP 401 / 403 —— 密钥无效或套餐无权访问 |
| `NotFoundException` | HTTP 404 |
| `RateLimitException` | 重试后仍为 HTTP 429；携带 `retryAfterSeconds` |
| `ApiException` | 其他任何非 2xx；携带 `statusCode` 和 `responseBody` |
| `TransportException` | 完全没有响应 —— DNS、连接、TLS、超时 |
| `MalformedResponseException` | 2xx 响应体缺少必需字段 |
| `WebhookVerificationException` | 签名、时间戳或负载被拒绝 |
| `ConfigurationException` | 构造时找不到可用的 PSR-18 客户端或 PSR-17 工厂 |

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

### 重试

HTTP `429`、`500`、`502`、`503` 和 `504` 会按指数退避自动重试（500 ms、1 s、2 s……，单次等待上限 30 s），与官方 TypeScript 客户端一致。传输层失败**不会**重试 —— 它们会立即以 `TransportException` 抛出。

## 功能范围

本客户端覆盖 REST API 和 Webhook 验证。

**WebSocket 流式推送是有意不包含的。** PHP 的请求-响应模型不适合长连接数据流，要正确实现需要 ReactPHP 或 Ratchet 外加一个受监督的 worker 进程。如果你需要实时流，请使用 TypeScript、Go、Python 或 .NET 客户端 —— 参见 [docs.finlight.me](https://docs.finlight.me)。若要将推送送入 PHP 应用，Webhook 是受支持的路径。

## 开发

```bash
composer install
composer test          # PHPUnit
composer stan          # PHPStan max 级别，针对 PHP 8.1–8.4 检查
```

CI 会在 PHP 8.1 至 8.4 上运行测试套件。

## 发布

该包通过 [Packagist](https://packagist.org/packages/finlight/client) 分发，Packagist 直接读取本仓库 —— 无需上传步骤。

1. 更新 `FinlightClient::VERSION` 并修改 `CHANGELOG.md`。
2. 打标签发布：`git tag v1.0.1 && git push --tags`。
3. 启用 GitHub 集成后，Packagist 会自动获取该标签（Packagist → 个人资料 → *Settings* → GitHub 集成，或使用包页面的 *Update* 按钮手动同步）。

## 许可证

MIT —— 参见 [LICENSE](LICENSE)。

## 相关资源

- 中文产品页：https://finlight.me/zh/news-api
