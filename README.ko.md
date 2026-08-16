# finlight PHP Client

*[English](README.md) | [简体中文](README.zh-CN.md) | [日本語](README.ja.md) | 한국어*

[![CI](https://github.com/callbk/finlight-client-php/actions/workflows/ci.yml/badge.svg)](https://github.com/callbk/finlight-client-php/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/finlight/client)](https://packagist.org/packages/finlight/client)
[![PHP Version](https://img.shields.io/packagist/dependency-v/finlight/client/php)](https://packagist.org/packages/finlight/client)
[![License](https://img.shields.io/packagist/l/finlight/client)](LICENSE)

[finlight.me](https://finlight.me) 금융 뉴스 API의 PHP 클라이언트입니다. 감성 점수와 엔티티 태그(티커, ISIN, OpenFIGI)가 부가된 시장 뉴스를 검색하고, finlight에서 수신한 Webhook을 검증할 수 있습니다.

전체 API 레퍼런스: **[docs.finlight.me](https://docs.finlight.me)** · API 키 발급: [app.finlight.me](https://app.finlight.me)

## 요구 사항

- PHP 8.1 이상
- 임의의 [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP 클라이언트(Guzzle이 설치되어 있으면 자동으로 사용됩니다)

## 설치

```bash
composer require finlight/client
```

프로젝트에 아직 PSR-18 클라이언트가 없다면 함께 설치하세요:

```bash
composer require finlight/client guzzlehttp/guzzle
```

## 빠른 시작

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

## 기사 검색

모든 필터는 선택 사항입니다. 필요한 것만 명명 인자로 전달하세요.

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

| 파라미터 | 타입 | 비고 |
| --- | --- | --- |
| `query` | `string` | 불리언 연산자, 필드 필터(`ticker:`, `source:`, `isin:`), 따옴표로 묶은 구문 |
| `tickers` | `string[]` | 예: `['AAPL', 'NVDA']` |
| `sources` | `string[]` | 기본 소스 집합을 대체합니다 |
| `optInSources` | `string[]` | 기본 집합에 추가합니다 |
| `excludeSources` | `string[]` | 결과에서 제외합니다 |
| `countries` | `string[]` | ISO 3166-1 alpha-2 |
| `categories` | `ArticleCategory[]` | 주제 필터 |
| `from` / `to` | `string` | `YYYY-MM-DD` 또는 ISO 8601 |
| `language` | `string` | ISO 639-1, 서버 기본값은 `en` |
| `includeContent` | `bool` | 기사 본문 전문 |
| `includeEntities` | `bool` | 태깅된 기업 데이터 |
| `excludeEmptyContent` | `bool` | 본문이 없는 기사 건너뛰기 |
| `orderBy` / `order` | `OrderBy` / `SortOrder` | 정렬 |
| `page` / `pageSize` | `int` | `pageSize`는 1~100 |

`ArticleResponse`는 `Countable`과 `IteratorAggregate`를 구현하므로 `count($response)`와 `foreach ($response as $article)`을 모두 사용할 수 있습니다. 원본 목록은 `$response->articles`로 계속 접근할 수 있습니다.

### URL로 단일 기사 조회

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

## 뉴스 소스

```php
$sources = $client->sources->getSources();

$defaults = array_filter($sources, static fn ($source) => $source->isDefaultSource);

echo count($defaults), ' of ', count($sources), " sources are on by default\n";
```

## Webhook

finlight는 모든 전송에 HMAC-SHA256 서명을 적용합니다. `WebhookService`는 서명을 검증하고, 타임스탬프 헤더가 있으면 5분의 재생 허용 범위를 적용한 뒤 파싱된 `Article`을 반환합니다.

이 서비스는 정적입니다. 클라이언트 인스턴스도 API 키도 필요하지 않습니다.

> **본문은 원본 그대로의, 파싱되지 않은 요청 바이트여야 합니다.** 읽기 전에 JSON을 디코딩하고 다시 인코딩하는 미들웨어가 있으면 서명이 무효화됩니다.

### 순수 PHP

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

// 빠르게 응답하고, 처리는 비동기로 수행하세요.
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

## 설정

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

| 옵션 | 기본값 | 의미 |
| --- | --- | --- |
| `apiKey` | — | 필수. `X-API-KEY` 헤더로 전송됩니다 |
| `baseUrl` | `https://api.finlight.me` | API 기본 URL |
| `timeoutMs` | `5000` | 이 라이브러리가 생성하는 HTTP 클라이언트에 적용됩니다 |
| `retryCount` | `3` | **총** 시도 횟수이므로 기본값은 1회 호출과 2회 재시도입니다 |

### 직접 만든 HTTP 클라이언트 사용

임의의 PSR-18 클라이언트를 사용할 수 있습니다. 타임아웃, 프록시, 미들웨어, 로깅을 제어하려면 주입하세요:

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

클라이언트를 주입하면 `timeoutMs`는 더 이상 적용되지 않습니다. PSR-18에는 이식 가능한 타임아웃 설정이 없으므로 직접 만든 클라이언트에서 설정하세요.

## 오류

이 라이브러리가 던지는 모든 예외는 `Finlight\Exception\FinlightException`을 구현하므로 catch 블록 하나로 전부 처리할 수 있습니다.

| 예외 | 발생 시점 |
| --- | --- |
| `AuthenticationException` | HTTP 401 / 403 — 키가 유효하지 않거나 요금제에 권한이 없음 |
| `NotFoundException` | HTTP 404 |
| `RateLimitException` | 재시도 후에도 HTTP 429. `retryAfterSeconds`를 포함합니다 |
| `ApiException` | 그 밖의 비 2xx. `statusCode`와 `responseBody`를 포함합니다 |
| `TransportException` | 응답이 전혀 없음 — DNS, 연결, TLS, 타임아웃 |
| `MalformedResponseException` | 2xx 본문에 필수 필드가 없음 |
| `WebhookVerificationException` | 서명, 타임스탬프 또는 페이로드가 거부됨 |
| `ConfigurationException` | 생성 시 PSR-18 클라이언트 또는 PSR-17 팩토리를 찾을 수 없음 |

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

### 재시도

HTTP `429`, `500`, `502`, `503`, `504`는 지수 백오프로 자동 재시도됩니다(500 ms, 1 s, 2 s, …, 1회 대기 최대 30 s). 이는 공식 TypeScript 클라이언트와 동일한 동작입니다. 전송 계층 실패는 재시도되지 **않으며**, 즉시 `TransportException`으로 표면화됩니다.

## 지원 범위

이 클라이언트는 REST API와 Webhook 검증을 다룹니다.

**WebSocket 스트리밍은 의도적으로 포함하지 않았습니다.** PHP의 요청-응답 모델은 장시간 유지되는 스트림에 적합하지 않으며, 제대로 구현하려면 ReactPHP 또는 Ratchet과 함께 감시되는 워커 프로세스가 필요합니다. 실시간 스트림이 필요하다면 TypeScript, Go, Python 또는 .NET 클라이언트를 사용하세요([docs.finlight.me](https://docs.finlight.me) 참조). PHP 애플리케이션으로의 푸시 전달에는 Webhook이 지원되는 방식입니다.

## 개발

```bash
composer install
composer test          # PHPUnit
composer stan          # PHPStan max 레벨, PHP 8.1~8.4 대상 검사
```

CI는 PHP 8.1부터 8.4까지 테스트 스위트를 실행합니다.

## 릴리스

이 패키지는 [Packagist](https://packagist.org/packages/finlight/client)를 통해 배포되며, Packagist가 이 저장소를 직접 읽습니다. 업로드 단계는 없습니다.

1. `FinlightClient::VERSION`을 올리고 `CHANGELOG.md`를 갱신합니다.
2. 릴리스에 태그를 붙입니다: `git tag v1.0.1 && git push --tags`.
3. GitHub 연동을 활성화해두면 Packagist가 태그를 자동으로 가져갑니다(Packagist → 프로필 → *Settings* → GitHub 연동, 또는 수동 동기화를 위한 패키지의 *Update* 버튼).

## 라이선스

MIT — [LICENSE](LICENSE) 참조.

## 관련 링크

- 한국어 제품 페이지: https://finlight.me/ko/news-api
