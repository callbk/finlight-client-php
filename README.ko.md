# finlight client for PHP

*[English](README.md) | [简体中文](README.zh-CN.md) | [日本語](README.ja.md) | 한국어*

> 이 번역은 [README.md](README.md)를 바탕으로 합니다. 내용이 다르면 영어판이 우선합니다.

> **호환되지 않는 릴리스입니다.** 2.0.0 버전은 finlight API v3만 지원합니다. 1.x용 코드는 그대로 실행되지 않습니다.
> 1.x(API v2)를 계속 쓰려면 `finlight/client:^1.0`을 지정하세요. [1.x에서 마이그레이션](#migrating-from-1x)을 참고하세요.

[finlight](https://finlight.me) 뉴스 API용 PHP 클라이언트입니다. REST로 금융 뉴스, 기업, 출처를 검색하고 조회하며,
직접 만든 소켓 루프를 위해 WebSocket 메시지를 만들고 읽고, webhook을 검증합니다.

## Versions

| SDK version | API version | Status |
|---|---|---|
| 2.x | v3 (REST `/v3`, WebSocket `payloadVersion` 3, v3 webhook) | 현재 버전 |
| 1.x | v2 | 더 이상 릴리스 없음 |

SDK 버전과 API 버전은 서로 독립적입니다. API v3를 지원하는 다른 finlight SDK: TypeScript 4.x, Python 3.x,
Go 모듈 `/v2`, .NET 2.x, Java 1.x, Ruby 1.x, Rust 0.2.

## Requirements

- PHP 8.2 이상, 64비트
- [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP 클라이언트와 [PSR-17](https://www.php-fig.org/psr/psr-17/) 팩토리.
  Guzzle 7이 설치되어 있으면 자동으로 사용합니다. 그 밖의 클라이언트는 `php-http/discovery` 1.19+로 찾습니다.
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

파라미터는 API의 키 이름(`eventTypes`, `orderBy` 등)을 가진 배열입니다. 설정한 키만 전송됩니다. 알 수 없는 키는 무엇이든
전송하기 전에 `FinlightValidationException`을 던집니다.

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

`searchAll()`은 페이지를 대신 순회합니다. 호출할 때 요청을 검사하고 고정하며, 이후 페이지에는 `cursor`만 추가합니다.
페이지마다 할당량을 쓰므로 `maxPages` 페이지(기본값 10) 후에 멈춥니다.

```php
$result = $client->articles->searchAll(['query' => 'ticker:AAPL', 'limit' => 100], maxPages: 5);
foreach ($result as $article) {        // or: foreach ($result->pages() as $page)
    echo $article->id, ' ', $article->title, "\n";
}
if ($result->truncated()) {
    echo 'continue with cursor ', $result->nextCursor(), "\n";
}
```

결과는 한 번만 순회할 수 있습니다. 잘못된 형식(예: `'limit' => 0` 또는 `'languages' => []`)은 전송 전에
`FinlightValidationException`을 던집니다. 어휘 값은 로컬에서 검사하지 않으므로, 서버의 새 값은 SDK 업데이트 없이도
동작합니다.

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

- 찾지 못하면 `NotFoundException`을 던집니다. 빈 결과가 아닙니다.
- 기사 id 도입 전에 색인된 기사는 id로 조회하면 404가 됩니다. 이런 기사는 `getByLink()`를 쓰세요.
- 조회 호출은 크레딧을 씁니다. `companies` 또는 `sources` 호출은 기사 요청처럼 월간 할당량에서 1 크레딧을 차감합니다(`getMany()`는
  요청당 한 번). 304 재검증과 오류 응답도 1 크레딧을 차감합니다. 월간 할당량과 버스트 제한이 적용되므로 여기서도 429가 날 수 있습니다.
- `companies` 호출에는 `entityAccess` 권한이 필요합니다(없으면 `ForbiddenException`).
- `companies->get()`은 최근 레코드 256개를 보관하고 ETag로 다시 검증합니다. 304 응답이면 보관한 레코드를 돌려줍니다.
  `ifNoneMatch:`를 넘기면 캐시를 직접 관리하며, 이때 304는 `null`을 돌려줍니다.
- `CompanyList`와 `SourceList`는 `foreach`와 `count()`로 쓸 수 있습니다. 읽지 못한 항목은 `skipped()`에 있습니다.

## Absent, null and empty

일부 키는 요금제나 `include`에 따라 달라지며, 세 가지 상태가 있습니다.

| 상태 | 의미 | 구별 방법 |
|---|---|---|
| 없음 | 요청하지 않음, 요금제에 없음, 또는 raw 채널 | `contentPresent()` / `sentimentPresent()`가 false. `companies`, `events`가 `null` |
| `null` | 요청했고 권한도 있지만 값이 없음 | `contentPresent()`가 true이고 `content`가 `null` |
| `[]` | 태깅했지만 결과 없음 | `companiesPresent()`가 true이고 `companies`가 `[]` |

- `content`와 `sentiment`에는 `contentPresent()`와 `sentimentPresent()`를 씁니다.
- `companies`, `events`, `events[].companyIds`, `Source::$hasContent`는 키가 없으면 `null`입니다.
  `companiesPresent()`와 `eventsPresent()`도 같은 뜻입니다. `companyIds`는 같은 응답에 `companies`가 있을 때만 옵니다.
- `media`, `countries`, `categories`는 없으면 `[]`입니다.
- 시간은 보내온 문자열 그대로 둡니다(`publishedAt`). `publishedAtTime()` 등 `...Time()` 메서드는 엄격하게 해석하며,
  읽을 수 없으면 `null`을 돌려줍니다.
- 새 서버가 보낸 알 수 없는 키는 무시합니다. `rawJson()`은 받은 JSON을 그대로 돌려줍니다.
- 어휘 값은 일반 문자열입니다. 상수(`EventType::MNA`, `CompanyRole::ACQUIRER` 등)와 비교하세요. 알 수 없는 값은 그대로
  유지됩니다.

## Real-time streams

이 SDK에는 WebSocket 전송 계층이 없습니다. 직접 만든 소켓 루프(예: `ratchet/pawl`, `amphp/websocket-client`)를 위해
메시지를 만들고 프레임을 읽습니다.

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

- `Subscribe::raw([...])`는 `wss://wss.finlight.me/raw`용 메시지를 만듭니다. 보강 전 기사이며, 키는 `query`, `sources`,
  `excludeSources`, `sourceTypes`, `excludeSourceTypes`, `language`, `includeUpdates`입니다. raw에는 `include`가 없습니다.
- 두 스트림 모두 검색과 같이 `sourceTypes`와 `excludeSourceTypes`(`SourceType` 값)를 받습니다. SDK는 값을 검사하지
  않습니다. 알 수 없는 값이면 서버가 `VALIDATION_FAILED`를 보내고 연결을 닫습니다.
- `Frame::decode()`는 v3 프레임과, 첫 구독 전에 서버가 보내는 v2 형식의 제어 프레임을 읽습니다. 종류: `created`,
  `updated`, `other`(이 SDK가 모르는 이벤트, 건너뛰세요), `admitted`, `pong`, `preempted`, `error`.
- 루프 규칙: 25초마다 `{"action":"ping","t":<epoch ms>}`를 보냅니다. 60초 동안 어떤 형식의 pong도 오지 않으면 닫고 다시
  연결합니다. 115분 후에는 새 연결을 엽니다. 다시 연결할 때마다 같은 구독을 보냅니다. 이벤트, `data.id`, `data`의 해시로
  중복을 걸러냅니다. 이 값들은 `FinlightConfig`(`pingInterval`, `watchdogTimeout`, `rotationInterval`)에 있습니다.
- 115분이 되면 이전 연결을 연 채로 새 연결을 열고 구독한 다음 이전 연결을 닫습니다. 요금제가 연결 한도에 도달해
  있으면(예: 한도 1) 이전 연결이 아직 계산되어 새 연결이 거부됩니다. 이 연결이 거부되거나 실패하면(403, 5xx, 네트워크
  오류) 이전 연결을 닫고 그 close 응답을 기다린 뒤(최대 5초) 바로 다시 연결합니다. 이 거부는 보고하지 않습니다. 이
  연결마저 거부될 때만 아래 설명대로 기다립니다. 완전히 멈추는 거부(아래)는 여기서도 멈춥니다.
- 다음 경우에는 완전히 멈춥니다: 연결 시 401, 연결 시 403 "Your subscription does not include WebSockets",
  `VALIDATION_FAILED`, 이유가 `connection_limit`인 `connection.preempted`, 코드 1008로 닫힌 연결. 그 밖의 종료, 5xx, `HANDSHAKE_FAILED`,
  `SEARCH_FAILED`에서는 대기 시간을 늘려 가며 다시 연결합니다. 연결 시 403 "Too many concurrent connections" 또는 429
  뒤에는 최소 60초를 기다립니다. `PING_ON_DEFAULT_ROUTE`, `INTERNAL_ERROR`, 알 수 없는 코드는 로그만 남깁니다.
- 늘려 가는 대기 시간은 `reconnectBaseDelay`(1초)에서 시작해 `reconnectMaxDelay`(30초)까지 두 배씩 늘어나며, pong이나
  기사가 도착했을 때만 처음으로 돌아갑니다. `connection.admitted`는 세지 않습니다. 서버가 구독을 검사하기 전에 오기
  때문에, admitted 뒤에 `SEARCH_FAILED`나 종료가 이어지는 루프도 계속 느려져야 합니다.
- WebSocket 라이브러리가 거부된 연결의 본문을 준다면 읽고, 403 텍스트는 앞부분으로 비교합니다. 읽을 수 있는 본문이 없으면
  401은 완전히 멈추고, 403은 최소 60초를 기다린 뒤 오류를 보고합니다.
- `SEARCH_FAILED`는 5번 연속으로 왔을 때만 보고합니다(`searchFailedThreshold`). 그동안에도 계속 다시 연결합니다.
- 월간 WebSocket 할당량을 다 쓰면(연결 시 403 "WebSocket quota exceeded", 또는 이유가 `quota_exceeded`인
  `connection.preempted`) 성공할 때까지 한 시간마다 다시 시도합니다(`quotaReconnect`, `quotaReconnectInterval`).
- 다시 연결한 뒤 자동으로 채워 주지 않습니다. 마지막으로 본 `availableAt`(raw: `firstSeenAt`)부터
  `articles->searchAll()`로 빈 곳을 채우세요.

알아 둘 제한:

- 요금제의 연결 수 한도는 enriched, raw, 이전 SDK 연결이 함께 씁니다. `x-takeover: true`
  (`Subscribe::dialHeaders($key, takeover: true)`)를 보내면 가장 오래된 연결을 대체합니다.
- 서버는 2시간 후에 연결을 닫습니다.
- 구독 직후 서버는 조건에 맞는 최신 기사를 한 번 보냅니다(다시 연결할 때마다 보냅니다). 오래된 기사일 수 있습니다.
- 32,000바이트를 넘는 실시간 프레임은 서버가 알림 없이 버립니다. 그런 기사는 `articles->get()`으로 가져오세요.
- `connection.admitted`는 서버가 구독을 검사하기 전에 옵니다. 구독이 받아들여졌다는 뜻이 아닙니다.

## Webhooks

finlight는 모든 webhook에 서명합니다. 무엇이든 해석하기 전에 **원본** 요청 본문으로 검증하세요.

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

- `$headers`에는 `$_SERVER`, `getallheaders()`, PSR-7 요청, 프레임워크의 헤더 백을 넘길 수 있습니다. 이름은 대소문자를
  구분하지 않고 찾습니다. `constructEventFromValues($rawBody, $signature, $timestamp, $secret)`는 두 헤더 값을 직접
  받습니다. `verifySignature()`는 검사만 합니다.
- 타임스탬프는 앞뒤 300초(`tolerance`) 안이어야 합니다.
- 대시보드의 테스트 webhook에서는 `$result->isTest()`가 true입니다. 요금제 제한을 받지 않습니다.
- 410으로 응답하지 마세요. webhook이 바로 비활성화됩니다. 2xx가 아닌 응답이 10번 이어져도 비활성화됩니다. 처리하지 않은
  `WrongPayloadVersion`은 500이 되므로 반드시 처리하세요.

원본 본문을 얻는 방법:

| 프레임워크 | 원본 본문 | 헤더 |
|---|---|---|
| 순수 PHP | `file_get_contents('php://input')` | `$_SERVER` |
| Laravel | `$request->getContent()` | `$request->headers->all()` |
| Symfony | `$request->getContent()` | `$request->headers->all()` |
| PSR-7 (Slim, Mezzio) | `$request->getBody()` | `$request` |

webhook을 v3로 전환하기: 먼저 이 SDK를 배포합니다. `WrongPayloadVersion`에는 2xx로 응답하고 `raw`를 보관합니다. 그다음
대시보드에서 webhook을 `payloadVersion` 3으로 바꿉니다. 전환 전에 대기열에 들어간 재전송이 옛 형식으로 오므로 약 2시간
기다립니다.

## Errors and retries

SDK가 던지는 모든 예외는 `FinlightException`을 구현하며 `kind()`가 있습니다.

| 예외 | `kind()` | 발생 시점 |
|---|---|---|
| `FinlightApiException` | `api` | API가 오류로 응답함. `status()`, `code()`, `type()`, `title()`, `detail()`, `requestId()`, `errors()`, `apiKind()`, `raw()`. 코드별 하위 클래스: `ValidationFailedException`, `CursorInvalidException`, `CursorMismatchException`, `UnauthorizedException`, `ForbiddenException`, `NotFoundException`, `RateLimitedException`, `InternalServerException`. 알 수 없는 코드는 기본 클래스(`apiKind()`는 `other`). |
| `RateLimitedException` | `api` | `limitType()`은 `monthly`, `burst`, `unknown` 또는 새 값. `retryAfter()`는 초 단위. |
| `FinlightValidationException` | `validation` | 요청이 로컬에서 거부됨(`InvalidArgumentException`이기도 함). |
| `FinlightNetworkException`, `FinlightTimeoutException` | `network`, `timeout` | 네트워크 장애 또는 시간 초과. 원래 오류는 `getPrevious()`. |
| `FinlightDecodeException` | `decode` | 응답, 프레임, webhook 본문을 읽을 수 없음. |
| `WebhookVerificationException` | `webhook_verification` | webhook 검증 실패(`reason()`). |

재시도: 5xx 응답, 네트워크 오류, 시간 초과는 대기 시간을 늘려 가며 최대 3번 재시도하며, 검색도 포함됩니다. 400, 401, 403,
404와 월간 429는 재시도하지 않습니다. 월간 429는 `searchAll()` 안에서도 바로 실패합니다. 버스트 429는 `Retry-After`초
(헤더가 없으면 10초)를 기다린 뒤 한 번 더 시도합니다. `retryOnBurst: 'off'`로 끌 수 있습니다. 모든 호출은 고유한
`X-Request-Id`를 보내며, 재시도할 때도 같은 값을 씁니다. `totalTimeout`은 재시도와 버스트 대기를 포함한 호출 전체 시간을
제한합니다. PHP-FPM에서는 이 값을 쓰세요.

PHP에는 취소 기능이 없어서 실행 중인 호출을 밖에서 멈출 수 없습니다. 대신 `totalTimeout`으로 시간을 제한하세요.

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

| 키 | 기본값 | |
|---|---|---|
| `apiKey` | 필수 | |
| `baseUrl` | `https://api.finlight.me` | |
| `wssUrl` | `wss://wss.finlight.me` | raw: `/raw` |
| `timeout` | 30초 | 시도마다 |
| `totalTimeout` | 없음 | 호출마다 |
| `maxRetries` | 3 | |
| `retryBaseDelay`, `retryMaxDelay` | 0.5초, 8초 | |
| `retryOnBurst`, `burstWait` | `once`, 10초 | `burstWait`는 `Retry-After`가 없을 때만 |
| `maxPages` | 10 | |
| `maxResponseBytes` | 32 MiB | |
| `companyCacheSize` | 256 | |
| `pingInterval`, `watchdogTimeout`, `rotationInterval` | 25초, 60초, 115분 | 소켓 루프용 |
| `reconnectBaseDelay`, `reconnectMaxDelay`, `longBackoff` | 1초, 30초, 60초 | 소켓 루프용 |
| `quotaReconnect`, `quotaReconnectInterval` | 켜짐, 1시간 | 소켓 루프용 |
| `searchFailedThreshold`, `dedupeCacheSize` | 5, 1000 | 소켓 루프용 |
| `maxMessageBytes`, `connectTimeout` | 32 MiB, 30초 | 소켓 루프용 |
| `logger` | 출력 없음 | PSR-3 |
| `clock` | 시스템 시계 | PSR-20. `sleep()`이 있는 시계는 대기에 사용됩니다 |
| `httpClient`, `requestFactory`, `streamFactory` | Guzzle 또는 discovery | PSR-18, PSR-17 |

모든 시간은 `float` 초 단위입니다. 직접 만든 `httpClient`를 쓰면 그 클라이언트의 시간 제한, 프록시, 미들웨어가
적용됩니다. 시도마다 할당량을 쓰므로 재시도 미들웨어는 추가하지 마세요. `totalTimeout`은 시도 사이에 계속 검사합니다.

## Logging

PSR-3 `logger`를 넘기면 SDK의 동작을 볼 수 있습니다. 넘기지 않으면 아무것도 출력하지 않습니다. 재시도(info), 버스트 대기,
건너뛴 항목과 멈춘 `searchAll()`(warning), 각 요청과 그 request id(debug)를 기록합니다. 메시지는 `finlight:`로
시작합니다. API 키, webhook 시크릿, 서명, 본문은 로그와 예외 메시지에 절대 나오지 않으며, 설정을 `var_dump()`하면 키는
`***`로 보입니다. SDK는 stdout에 쓰지 않고 핸들러도 등록하지 않습니다.

## Data notes

- 오래된 기사는 `publishedAt`이 `"1970-01-01T00:00:00.000Z"`일 수 있습니다. 보낸 그대로 전달됩니다.
- 빈 텍스트는 `null`로 옵니다(`summary`, `content`).
- 역할 기능 이전에 태깅된 기사는 `role`과 `relevance`가 `null`이고 `events`가 `[]`입니다.
- id 도입 전에 색인된 기사는 `articles->get($id)`가 404입니다. `getByLink()`를 쓰세요.
- 오래된 기사는 `availableAt`이 `null`이며, raw 스트림에서는 항상 `null`입니다.
- `source->type`과 `Source::$type`은 `SourceType` 값을 가집니다. 소스가 분류되지 않았으면 `null`입니다. 소스 유형 도입 전에
  색인된 기사도 `source->type`이 `null`입니다. `search()`, `searchAll()`, `Subscribe::enriched()`, `Subscribe::raw()`에서
  `sourceTypes`와 `excludeSourceTypes`로 거를 수 있습니다. 분류되지 않은 소스는 `sourceTypes`에 맞지 않고
  `excludeSourceTypes`에서는 남습니다. v3 웹훅도 같은 두 필터를 받으며, 설정은 대시보드에서 합니다.

## Migrating from 1.x

전체 안내는 [docs.finlight.me](https://docs.finlight.me/migration/v2-to-v3)에 있습니다. 요약:

| 1.x | 2.x |
|---|---|
| `new Config('key', timeoutMs: 5000)` | `new FinlightConfig(apiKey: 'key', timeout: 5.0)` |
| `$client->articles->fetchArticles(new GetArticlesParams(...))` | `$client->articles->search([...])`, `searchAll([...])` |
| `fetchArticleByLink(new GetArticleByLinkParams(...))` | `getByLink($link, include: [...])`, `get($id)` |
| `page` / `pageSize` | `limit` / `cursor` |
| `includeContent`, `includeEntities` | `'include' => ['content', 'companies', 'events']` |
| `OrderBy::PublishDate`, `SortOrder::Desc` (열거형) | `'orderBy' => 'publishedAt'`, `'order' => 'desc'` (문자열) |
| `language` | `languages` (목록) |
| `$article->publishDate`, `createdAt`, `revisedDate` (DateTime) | `publishedAt`, `firstSeenAt`, `revisedAt` (문자열), `publishedAtTime()` |
| `$article->images` | `$article->media[]->url` |
| `$article->source` (문자열) | `$article->source->domain` |
| `$article->sentiment`, `confidence` | `$article->sentiment->label`, `->confidence` |
| `$client->sources->getSources()` | `$client->sources->list()` |
| `WebhookService::constructEvent($body, $sig, $secret, $ts)` | `Webhooks::constructEvent($body, $headers, $secret)` |
| webhook `isUpdate` | `$event->event === 'article.updated'` |
| `ApiException`, `AuthenticationException`, `RateLimitException`, `TransportException` | `FinlightApiException`, `UnauthorizedException` / `ForbiddenException`, `RateLimitedException`, `FinlightNetworkException` |

webhook 전환은 네 단계입니다: 2.x를 배포합니다. `WrongPayloadVersion`에 2xx로 응답하고 `raw`를 보관합니다. 대시보드에서
webhook을 `payloadVersion` 3으로 바꿉니다. 옛 재전송이 끝날 때까지 약 2시간 기다립니다.

## Development

```bash
composer install
composer test                  # unit and conformance tests, no network
composer stan                  # PHPStan, level max
composer sync-conformance -- --from=<monorepo>/shared/contracts/api-v3
composer check                 # kit MANIFEST, case map, README skeleton, no v1/v2 leftovers
```

`CONFORMANCE.md`는 계약 키트(`conformance/`, 버전은 `CONTRACT_VERSION`, `MANIFEST`로 검증)의 모든 케이스를 해당 테스트에
연결합니다. 라이브 테스트는 dev에서만 실행하며 `FINLIGHT_API_KEY`, `FINLIGHT_BASE_URL`, `FINLIGHT_WSS_URL`이 필요합니다:
`php scripts/live-smoke.php`. 운영 호스트는 거부합니다.

## License

MIT
