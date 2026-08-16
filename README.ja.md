# finlight PHP Client

*[English](README.md) | [简体中文](README.zh-CN.md) | 日本語 | [한국어](README.ko.md)*

[![CI](https://github.com/callbk/finlight-client-php/actions/workflows/ci.yml/badge.svg)](https://github.com/callbk/finlight-client-php/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/finlight/client)](https://packagist.org/packages/finlight/client)
[![PHP Version](https://img.shields.io/packagist/dependency-v/finlight/client/php)](https://packagist.org/packages/finlight/client)
[![License](https://img.shields.io/packagist/l/finlight/client)](LICENSE)

[finlight.me](https://finlight.me) 金融ニュース API の PHP クライアントです。センチメントスコアとエンティティタグ（ティッカー、ISIN、OpenFIGI）が付与されたマーケットニュースを検索し、finlight から届く Webhook を検証できます。

完全な API リファレンス: **[docs.finlight.me](https://docs.finlight.me)** · API キーの取得: [app.finlight.me](https://app.finlight.me)

## 動作要件

- PHP 8.1 以降
- 任意の [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP クライアント（Guzzle がインストールされていれば自動的に使用されます）

## インストール

```bash
composer require finlight/client
```

プロジェクトにまだ PSR-18 クライアントがない場合は、あわせて追加してください:

```bash
composer require finlight/client guzzlehttp/guzzle
```

## クイックスタート

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

## 記事の検索

フィルタはすべて任意です。必要なものだけを名前付き引数で渡してください。

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

| パラメータ | 型 | 備考 |
| --- | --- | --- |
| `query` | `string` | ブール演算子、フィールドフィルタ（`ticker:`、`source:`、`isin:`）、引用符で囲んだフレーズ |
| `tickers` | `string[]` | 例: `['AAPL', 'NVDA']` |
| `sources` | `string[]` | デフォルトのソース集合を置き換えます |
| `optInSources` | `string[]` | デフォルト集合に追加します |
| `excludeSources` | `string[]` | 結果から除外します |
| `countries` | `string[]` | ISO 3166-1 alpha-2 |
| `categories` | `ArticleCategory[]` | トピックフィルタ |
| `from` / `to` | `string` | `YYYY-MM-DD` または ISO 8601 |
| `language` | `string` | ISO 639-1。サーバー側のデフォルトは `en` |
| `includeContent` | `bool` | 記事本文の全文 |
| `includeEntities` | `bool` | タグ付けされた企業データ |
| `excludeEmptyContent` | `bool` | 本文のない記事をスキップ |
| `orderBy` / `order` | `OrderBy` / `SortOrder` | 並び替え |
| `page` / `pageSize` | `int` | `pageSize` は 1〜100 |

`ArticleResponse` は `Countable` と `IteratorAggregate` を実装しているため、`count($response)` と `foreach ($response as $article)` の両方が使えます。生のリストは `$response->articles` から引き続き利用できます。

### URL から単一の記事を取得する

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

## ニュースソース

```php
$sources = $client->sources->getSources();

$defaults = array_filter($sources, static fn ($source) => $source->isDefaultSource);

echo count($defaults), ' of ', count($sources), " sources are on by default\n";
```

## Webhook

finlight はすべての配信に HMAC-SHA256 で署名します。`WebhookService` は署名を検証し、タイムスタンプヘッダーがある場合は 5 分のリプレイ許容範囲を適用したうえで、パース済みの `Article` を返します。

このサービスは静的です。クライアントのインスタンスも API キーも不要です。

> **ボディは生の、パースされていないリクエストバイトである必要があります。** 読み取り前に JSON をデコードして再エンコードするミドルウェアがあると、署名は無効になります。

### 素の PHP

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

// すばやく応答し、処理は非同期で行ってください。
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

## 設定

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

| オプション | デフォルト | 意味 |
| --- | --- | --- |
| `apiKey` | — | 必須。`X-API-KEY` ヘッダーとして送信されます |
| `baseUrl` | `https://api.finlight.me` | API のベース URL |
| `timeoutMs` | `5000` | 本ライブラリが生成する HTTP クライアントに適用されます |
| `retryCount` | `3` | **総**試行回数。デフォルトは 1 回の呼び出しと 2 回のリトライです |

### 独自の HTTP クライアントを使う

任意の PSR-18 クライアントが利用できます。タイムアウト、プロキシ、ミドルウェア、ロギングを制御したい場合は注入してください:

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

クライアントを注入した場合、`timeoutMs` は適用されなくなります。PSR-18 には移植可能なタイムアウト設定がないため、ご自身のクライアント側で設定してください。

## エラー

本ライブラリが送出するすべての例外は `Finlight\Exception\FinlightException` を実装しているため、1 つの catch ブロックで網羅できます。

| 例外 | 発生条件 |
| --- | --- |
| `AuthenticationException` | HTTP 401 / 403 — キーが無効、またはプランに権限がない |
| `NotFoundException` | HTTP 404 |
| `RateLimitException` | リトライ後も HTTP 429。`retryAfterSeconds` を保持します |
| `ApiException` | その他の非 2xx。`statusCode` と `responseBody` を保持します |
| `TransportException` | 応答がまったくない — DNS、接続、TLS、タイムアウト |
| `MalformedResponseException` | 2xx のボディに必須フィールドが欠けている |
| `WebhookVerificationException` | 署名、タイムスタンプ、またはペイロードが拒否された |
| `ConfigurationException` | 構築時に PSR-18 クライアントまたは PSR-17 ファクトリが見つからない |

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

### リトライ

HTTP `429`、`500`、`502`、`503`、`504` は指数バックオフで自動的にリトライされます（500 ms、1 s、2 s、……、1 回の待機は最大 30 s）。これは公式 TypeScript クライアントと同じ挙動です。トランスポート層の失敗は**リトライされず**、ただちに `TransportException` として表面化します。

## 対応範囲

本クライアントは REST API と Webhook 検証をカバーします。

**WebSocket ストリーミングは意図的に含めていません。** PHP のリクエスト・レスポンスモデルは長時間の接続に向いておらず、適切に実装するには ReactPHP または Ratchet に加えて、監視付きのワーカープロセスが必要になります。ライブストリームが必要な場合は TypeScript、Go、Python、.NET のクライアントをご利用ください（[docs.finlight.me](https://docs.finlight.me) を参照）。PHP アプリケーションへのプッシュ配信としては、Webhook がサポートされている方法です。

## 開発

```bash
composer install
composer test          # PHPUnit
composer stan          # PHPStan の max レベル、PHP 8.1〜8.4 に対して検査
```

CI は PHP 8.1 から 8.4 までテストスイートを実行します。

## リリース

本パッケージは [Packagist](https://packagist.org/packages/finlight/client) から配布され、Packagist はこのリポジトリを直接読み取ります。アップロード手順はありません。

1. `FinlightClient::VERSION` を更新し、`CHANGELOG.md` を編集します。
2. リリースにタグを付けます: `git tag v1.0.1 && git push --tags`。
3. GitHub 連携を有効にしておけば、Packagist がタグを自動的に取得します（Packagist → プロフィール → *Settings* → GitHub 連携、または手動同期用のパッケージページの *Update* ボタン）。

## ライセンス

MIT — [LICENSE](LICENSE) を参照してください。

## 関連リンク

- 日本語の製品ページ: https://finlight.me/ja/news-api
