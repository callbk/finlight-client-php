# finlight client for PHP

*[English](README.md) | [简体中文](README.zh-CN.md) | 日本語 | [한국어](README.ko.md)*

> この翻訳は [README.md](README.md) に基づいています。内容が異なる場合は英語版が優先されます。

> **互換性のないリリースです。** バージョン 2.0.0 は finlight API v3 のみに対応します。1.x 向けのコードはそのままでは
> 動きません。1.x（API v2）を使い続ける場合は `finlight/client:^1.0` を指定してください。
> [1.x からの移行](#migrating-from-1x) を参照してください。

[finlight](https://finlight.me) ニュース API の PHP クライアントです。REST で金融ニュース、企業、ソースを検索・取得し、
独自のソケットループ向けに WebSocket メッセージを組み立てて読み取り、webhook を検証します。

## Versions

| SDK version | API version | Status |
|---|---|---|
| 2.x | v3（REST `/v3`、WebSocket `payloadVersion` 3、v3 webhook） | 現行 |
| 1.x | v2 | 今後のリリースなし |

SDK のバージョンと API のバージョンは独立しています。API v3 に対応する他の finlight SDK：TypeScript 4.x、Python 3.x、
Go モジュール `/v2`、.NET 2.x、Java 1.x、Ruby 1.x、Rust 0.2。

## Requirements

- PHP 8.2 以降（64 ビット）
- [PSR-18](https://www.php-fig.org/psr/psr-18/) の HTTP クライアントと [PSR-17](https://www.php-fig.org/psr/psr-17/)
  のファクトリ。Guzzle 7 がインストールされていれば自動で使われます。それ以外は `php-http/discovery` 1.19+ で探します。
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

パラメータは API のキー名（`eventTypes`、`orderBy` など）を持つ配列です。設定したキーだけが送信されます。未知のキーは、
何かを送信する前に `FinlightValidationException` をスローします。

## Search articles

```php
$page = $client->articles->search([
    'query' => 'eventType:mna AND companyCountry:US',
    'languages' => ['en', 'de'],
    'from' => '2026-11-01',            // a string is sent as it is
    'to' => new DateTimeImmutable(),    // a DateTimeInterface is sent as UTC with milliseconds
    'eventTypes' => ['mna'],
    'minRelevance' => 0.5,
    'orderBy' => 'availableAt',
    'include' => ['content', 'companies', 'events'],
    'limit' => 50,
]);
echo $page->pagination->hasMore ? 'more' : 'done', ' ', $page->pagination->nextCursor ?? '', "\n";
```

`searchAll()` はページを順にたどります。呼び出した時点でリクエストを検査して固定し、以降のページでは `cursor` だけを
追加します。各ページがクォータを消費するため、`maxPages` ページ（デフォルト 10）で停止します。

```php
$result = $client->articles->searchAll(['query' => 'ticker:AAPL', 'limit' => 100], maxPages: 5);
foreach ($result as $article) {        // or: foreach ($result->pages() as $page)
    echo $article->id, ' ', $article->title, "\n";
}
if ($result->truncated()) {
    echo 'continue with cursor ', $result->nextCursor(), "\n";
}
```

結果は一度だけ反復できます。形式の誤り（例：`'limit' => 0` や `'languages' => []`）は送信前に
`FinlightValidationException` をスローします。語彙の値はローカルでは検査しないため、サーバーの新しい値は SDK を
更新しなくても使えます。

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

- 見つからない場合は `NotFoundException` をスローします。空の結果にはなりません。
- 記事 id の導入前にインデックスされた記事は、id で取得すると 404 になります。`getByLink()` を使ってください。
- `companies` の呼び出しには `entityAccess` の権限が必要です（ない場合は `ForbiddenException`）。
- `companies->get()` は直近 256 件のレコードを保持し、ETag で再検証します。304 の場合は保持したレコードを返します。
  `ifNoneMatch:` を渡すとキャッシュを自分で管理でき、その場合 304 は `null` を返します。
- `CompanyList` と `SourceList` は `foreach` と `count()` で使えます。読み取れなかった項目は `skipped()` にあります。

## Absent, null and empty

一部のキーはプランや `include` によって変わり、三つの状態があります。

| 状態 | 意味 | 見分け方 |
|---|---|---|
| なし | 要求していない、プラン外、または raw チャネル | `contentPresent()` / `sentimentPresent()` が false。`companies`、`events` が `null` |
| `null` | 要求済みで権限もあるが値がない | `contentPresent()` が true で `content` が `null` |
| `[]` | タグ付け済みだが該当なし | `companiesPresent()` が true で `companies` が `[]` |

- `content` と `sentiment` には `contentPresent()` と `sentimentPresent()` を使います。
- `companies`、`events`、`events[].companyIds`、`Source::$hasContent` はキーがない場合 `null` です。
  `companiesPresent()` と `eventsPresent()` も同じ意味です。`companyIds` は同じ応答に `companies` がある場合だけ送られます。
- `media`、`countries`、`categories` はない場合 `[]` です。
- 時刻は送られた文字列のまま保持されます（`publishedAt`）。`publishedAtTime()` などの `...Time()` メソッドは厳密に解析し、
  読み取れない場合は `null` を返します。
- 新しいサーバーからの未知のキーは無視されます。`rawJson()` は受信した JSON をそのまま返します。
- 語彙の値は通常の文字列です。定数（`EventType::MNA`、`CompanyRole::ACQUIRER` など）と比較してください。未知の値は
  そのまま保持されます。

## Real-time streams

この SDK には WebSocket の通信部分がありません。独自のソケットループ（例：`ratchet/pawl` や
`amphp/websocket-client`）向けにメッセージを組み立て、フレームを読み取ります。

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

- `Subscribe::raw([...])` は `wss://wss.finlight.me/raw` 向けのメッセージを組み立てます。エンリッチ前の記事で、キーは
  `query`、`sources`、`excludeSources`、`language`、`includeUpdates` です。raw には `include` がありません。
- `Frame::decode()` は v3 のフレームと、最初の購読前にサーバーが送る v2 形式の制御フレームを読み取ります。種類：`created`、
  `updated`、`other`（この SDK が知らないイベント。読み飛ばしてください）、`admitted`、`pong`、`preempted`、`error`。
- ループのルール：25 秒ごとに `{"action":"ping","t":<epoch ms>}` を送ります。60 秒間どちらの形式の pong も来なければ、
  閉じて再接続します。115 分後に新しい接続を開きます。再接続のたびに同じ購読を送ります。イベント、`data.id`、`data` の
  ハッシュで重複を除きます。これらの値は `FinlightConfig`（`pingInterval`、`watchdogTimeout`、`rotationInterval`）に
  あります。
- 次の場合は完全に停止します：接続時の 401、接続時の 403 "Your subscription does not include WebSockets"、
  `VALIDATION_FAILED`、理由が `connection_limit` の `connection.preempted`、コード 1008 での切断。その他の切断、5xx、`HANDSHAKE_FAILED`、
  `SEARCH_FAILED` では待ち時間を延ばしながら再接続します。接続時の 403 "Too many concurrent connections" または 429 の後は
  60 秒以上待ちます。`PING_ON_DEFAULT_ROUTE`、`INTERNAL_ERROR`、未知のコードはログに記録するだけです。
- WebSocket ライブラリが拒否された接続のボディを渡してくれる場合は読み、403 のテキストは先頭で比較します。読めるボディが
  ない場合、401 は完全に停止し、403 は 60 秒以上待ってからエラーを報告します。
- `SEARCH_FAILED` は 5 回続いたときだけ報告します（`searchFailedThreshold`）。その間も再接続を続けます。
- 月間の WebSocket クォータを使い切った場合（接続時の 403 "WebSocket quota exceeded"、または理由が `quota_exceeded` の
  `connection.preempted`）、成功するまで 1 時間ごとに再試行します（`quotaReconnect`、`quotaReconnectInterval`）。
- 再接続後に自動で補完はしません。最後に見た `availableAt`（raw では `firstSeenAt`）から `articles->searchAll()` で
  補ってください。

知っておくべき制限：

- プランの接続数上限は、enriched、raw、旧 SDK の接続で共有されます。`x-takeover: true`
  （`Subscribe::dialHeaders($key, takeover: true)`）を送ると、最も古い接続を置き換えます。
- サーバーは 2 時間後に接続を閉じます。
- 購読の直後に、サーバーは条件に合う最新の記事を一度送ります（再接続のたびにも送ります）。古い記事のこともあります。
- 32,000 バイトを超えるライブフレームは、通知なしにサーバーで破棄されます。その記事は `articles->get()` で取得してください。
- `connection.admitted` はサーバーが購読を検査する前に届きます。購読が受け入れられたという意味ではありません。

## Webhooks

finlight はすべての webhook に署名します。何かを解析する前に、**生の**リクエストボディで検証してください。

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

- `$headers` には `$_SERVER`、`getallheaders()`、PSR-7 リクエスト、フレームワークのヘッダーバッグを渡せます。名前は
  大文字・小文字を区別せずに照合します。`constructEventFromValues($rawBody, $signature, $timestamp, $secret)` は二つの
  ヘッダー値を直接受け取ります。`verifySignature()` は検査だけを行います。
- タイムスタンプは前後 300 秒（`tolerance`）以内である必要があります。
- ダッシュボードのテスト webhook では `$result->isTest()` が true です。プランによる制限は受けません。
- 410 は決して返さないでください。webhook がすぐに無効になります。2xx 以外の応答が 10 回続いても無効になります。
  処理しない `WrongPayloadVersion` は 500 になるので、必ず処理してください。

生のボディの取得方法：

| フレームワーク | 生のボディ | ヘッダー |
|---|---|---|
| 素の PHP | `file_get_contents('php://input')` | `$_SERVER` |
| Laravel | `$request->getContent()` | `$request->headers->all()` |
| Symfony | `$request->getContent()` | `$request->headers->all()` |
| PSR-7（Slim、Mezzio） | `$request->getBody()` | `$request` |

webhook を v3 に切り替える手順：まずこの SDK をデプロイします。`WrongPayloadVersion` には 2xx を返し、`raw` を保存します。
次にダッシュボードで webhook を `payloadVersion` 3 に切り替えます。切り替え前にキューに入った再送は古い形式で届くため、
約 2 時間待ちます。

## Errors and retries

SDK がスローする例外はすべて `FinlightException` を実装し、`kind()` を持ちます。

| 例外 | `kind()` | 発生する場合 |
|---|---|---|
| `FinlightApiException` | `api` | API がエラーを返した。`status()`、`code()`、`type()`、`title()`、`detail()`、`requestId()`、`errors()`、`apiKind()`、`raw()`。コードごとのサブクラス：`ValidationFailedException`、`CursorInvalidException`、`CursorMismatchException`、`UnauthorizedException`、`ForbiddenException`、`NotFoundException`、`RateLimitedException`、`InternalServerException`。未知のコードは基底クラスのまま（`apiKind()` は `other`）。 |
| `RateLimitedException` | `api` | `limitType()` は `monthly`、`burst`、`unknown` または新しい値。`retryAfter()` は秒。 |
| `FinlightValidationException` | `validation` | リクエストがローカルで拒否された（`InvalidArgumentException` でもある）。 |
| `FinlightNetworkException`、`FinlightTimeoutException` | `network`、`timeout` | ネットワーク障害またはタイムアウト。元のエラーは `getPrevious()`。 |
| `FinlightDecodeException` | `decode` | 応答、フレーム、webhook ボディを読み取れなかった。 |
| `WebhookVerificationException` | `webhook_verification` | webhook の検証に失敗した（`reason()`）。 |

再試行：5xx 応答、ネットワークエラー、タイムアウトは、待ち時間を延ばしながら最大 3 回再試行します。検索も対象です。
400、401、403、404 と月間の 429 は再試行しません。月間の 429 は `searchAll()` の中でも即座に失敗します。バーストの 429 は
`Retry-After` 秒（ヘッダーがなければ 10 秒）待ってから一度だけ再試行します。`retryOnBurst: 'off'` で無効にできます。
各呼び出しは独自の `X-Request-Id` を送り、再試行でも同じ値を使います。`totalTimeout` は再試行とバースト待ちを含めた
呼び出し全体の時間を制限します。PHP-FPM ではこれを使ってください。

PHP にはキャンセルの仕組みがないため、実行中の呼び出しを外から止めることはできません。代わりに `totalTimeout` で時間を制限してください。

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

| キー | デフォルト | |
|---|---|---|
| `apiKey` | 必須 | |
| `baseUrl` | `https://api.finlight.me` | |
| `wssUrl` | `wss://wss.finlight.me` | raw：`/raw` |
| `timeout` | 30 秒 | 試行ごと |
| `totalTimeout` | なし | 呼び出しごと |
| `maxRetries` | 3 | |
| `retryBaseDelay`、`retryMaxDelay` | 0.5 秒、8 秒 | |
| `retryOnBurst`、`burstWait` | `once`、10 秒 | `burstWait` は `Retry-After` がない場合のみ |
| `maxPages` | 10 | |
| `maxResponseBytes` | 32 MiB | |
| `companyCacheSize` | 256 | |
| `pingInterval`、`watchdogTimeout`、`rotationInterval` | 25 秒、60 秒、115 分 | ソケットループ用 |
| `reconnectBaseDelay`、`reconnectMaxDelay`、`longBackoff` | 1 秒、30 秒、60 秒 | ソケットループ用 |
| `quotaReconnect`、`quotaReconnectInterval` | 有効、1 時間 | ソケットループ用 |
| `searchFailedThreshold`、`dedupeCacheSize` | 5、1000 | ソケットループ用 |
| `maxMessageBytes`、`connectTimeout` | 32 MiB、30 秒 | ソケットループ用 |
| `logger` | 出力なし | PSR-3 |
| `clock` | システム時計 | PSR-20。`sleep()` を持つ時計は待ち時間に使われます |
| `httpClient`、`requestFactory`、`streamFactory` | Guzzle または discovery | PSR-18、PSR-17 |

時間はすべて `float` の秒です。独自の `httpClient` を使う場合は、そのタイムアウト、プロキシ、ミドルウェアが適用されます。
試行ごとにクォータを消費するため、再試行のミドルウェアは追加しないでください。`totalTimeout` は試行の合間に引き続き
検査されます。

## Logging

PSR-3 の `logger` を渡すと SDK の動作を確認できます。渡さなければ何も出力しません。再試行（info）、バースト待ち、
読み飛ばした項目、停止した `searchAll()`（warning）、各リクエストとその request id（debug）を記録します。メッセージは
`finlight:` で始まります。API キー、webhook のシークレット、署名、ボディはログにも例外メッセージにも出ません。設定を
`var_dump()` するとキーは `***` と表示されます。SDK は stdout に書き込まず、ハンドラーも登録しません。

## Data notes

- 古い記事の `publishedAt` は `"1970-01-01T00:00:00.000Z"` のことがあります。送られたまま届きます。
- 空のテキストは `null` で届きます（`summary`、`content`）。
- ロール導入前にタグ付けされた記事は、`role` と `relevance` が `null`、`events` が `[]` です。
- id 導入前にインデックスされた記事では、`articles->get($id)` が 404 になります。`getByLink()` を使ってください。
- 古い記事の `availableAt` は `null` で、raw ストリームでは常に `null` です。

## Migrating from 1.x

詳しいガイドは [docs.finlight.me](https://docs.finlight.me/migration/v2-to-v3) にあります。要点は次のとおりです。

| 1.x | 2.x |
|---|---|
| `new Config('key', timeoutMs: 5000)` | `new FinlightConfig(apiKey: 'key', timeout: 5.0)` |
| `$client->articles->fetchArticles(new GetArticlesParams(...))` | `$client->articles->search([...])`、`searchAll([...])` |
| `fetchArticleByLink(new GetArticleByLinkParams(...))` | `getByLink($link, include: [...])`、`get($id)` |
| `page` / `pageSize` | `limit` / `cursor` |
| `includeContent`、`includeEntities` | `'include' => ['content', 'companies', 'events']` |
| `OrderBy::PublishDate`、`SortOrder::Desc`（列挙型） | `'orderBy' => 'publishedAt'`、`'order' => 'desc'`（文字列） |
| `language` | `languages`（リスト） |
| `$article->publishDate`、`createdAt`、`revisedDate`（DateTime） | `publishedAt`、`firstSeenAt`、`revisedAt`（文字列）、`publishedAtTime()` |
| `$article->images` | `$article->media[]->url` |
| `$article->source`（文字列） | `$article->source->domain` |
| `$article->sentiment`、`confidence` | `$article->sentiment->label`、`->confidence` |
| `$client->sources->getSources()` | `$client->sources->list()` |
| `WebhookService::constructEvent($body, $sig, $secret, $ts)` | `Webhooks::constructEvent($body, $headers, $secret)` |
| webhook `isUpdate` | `$event->event === 'article.updated'` |
| `ApiException`、`AuthenticationException`、`RateLimitException`、`TransportException` | `FinlightApiException`、`UnauthorizedException` / `ForbiddenException`、`RateLimitedException`、`FinlightNetworkException` |

webhook の切り替えは 4 段階です：2.x をデプロイする。`WrongPayloadVersion` に 2xx を返し `raw` を保存する。ダッシュボードで
webhook を `payloadVersion` 3 に切り替える。古い再送が終わるまで約 2 時間待つ。

## Development

```bash
composer install
composer test                  # unit and conformance tests, no network
composer stan                  # PHPStan, level max
composer sync-conformance -- --from=<monorepo>/shared/contracts/api-v3
composer check                 # kit MANIFEST, case map, README skeleton, no v1/v2 leftovers
```

`CONFORMANCE.md` は、契約キット（`conformance/`。バージョンは `CONTRACT_VERSION`、`MANIFEST` で検証）の各ケースを
テストに対応付けます。ライブテストは dev に対してのみ実行し、`FINLIGHT_API_KEY`、`FINLIGHT_BASE_URL`、
`FINLIGHT_WSS_URL` が必要です：`php scripts/live-smoke.php`。本番ホストは拒否します。

## License

MIT
