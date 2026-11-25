<?php

declare(strict_types=1);

namespace Finlight\Tests\Conformance;

use Finlight\FinlightApiException;
use Finlight\FinlightConfig;
use Finlight\FinlightValidationException;
use Finlight\PreemptedInfo;
use Finlight\Subscribe;
use Finlight\Tests\Support\Kit;
use Finlight\Tests\Support\SessionModel;
use Finlight\Tests\Support\Wire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S-01, S-03 .. S-29: the WebSocket sessions of the kit at the decoder level. PHP has no WebSocket
 * transport; the sessions drive Subscribe (what the loop sends), Frame::decode (what it receives)
 * and a reference loop in tests/Support/SessionModel.php that applies the documented connection
 * rules. S-02 (raw header bytes) is in WsRawBytesTest.
 */
final class SessionsTest extends TestCase
{
    /** Sessions whose client frame no SDK may produce: the builder must refuse it locally. */
    private const REFUSED_LOCALLY = ['S-06', 'S-07', 'S-08'];

    /**
     * @return array<string, array{string}>
     */
    public static function sessionCases(): array
    {
        return Kit::cases('ws-sessions');
    }

    #[DataProvider('sessionCases')]
    public function testSession(string $rel): void
    {
        $fx = Kit::load($rel);
        $id = Kit::str($fx['id']);
        $raw = $fx['endpoint'] === 'raw';
        $clientConfig = Kit::obj($fx['clientConfig'] ?? []);
        $config = new FinlightConfig(
            apiKey: Kit::KEY,
            quotaReconnect: (bool) ($clientConfig['quotaReconnect'] ?? true),
        );
        self::assertTrue((new FinlightConfig('k'))->quotaReconnect, 'quotaReconnect is on by default');
        $model = new SessionModel($config, autoPong: $id !== 'S-16');
        $lastSubscribe = null;
        $pingsChecked = 0;
        $refused = 0;
        $afterExpect = false;

        foreach (Kit::list($fx['steps']) as $i => $step) {
            $step = Kit::obj($step);
            $where = sprintf('%s step %d (%s)', $id, $i, Kit::str($step['step']));
            // Outcomes collect from one expect block to the next; consecutive expects see the same ones.
            if ($step['step'] !== 'expect' && $afterExpect) {
                $model->outcomes = [];
                $afterExpect = false;
            }
            switch ($step['step']) {
                case 'dial':
                    $server = Kit::obj($step['server']);
                    self::assertSame(str_starts_with(Kit::str($step['url']), 'wss://wss.finlight.me/raw'), $raw);
                    $want = Kit::obj($step['expectRequestHeaders']);
                    if (isset($want['x-api-key'])) {
                        $headers = Subscribe::dialHeaders(Kit::str($want['x-api-key']));
                        self::assertSame($want['x-api-key'], $headers['x-api-key'] ?? null, $where);
                    }
                    if ($server['status'] === 101) {
                        $model->dialed();
                    } else {
                        self::assertIsInt($server['status']);
                        $model->dialRefused($server['status'], Kit::str($server['body'] ?? ''));
                    }
                    break;
                case 'clientSends':
                    if (isset($step['match'])) {
                        // A keep-alive ping of the loop: {"action":"ping","t":<number>}.
                        self::assertGreaterThan($pingsChecked, count($model->pings), $where . ': no ping went out');
                        $pingsChecked = count($model->pings);
                        $ping = Kit::obj(json_decode($model->pingFrame(), true));
                        foreach (Kit::obj($step['match']) as $k => $v) {
                            self::assertSame($v, $ping[$k]);
                        }
                        self::assertIsInt($ping['t']);
                        break;
                    }
                    $frame = $step['frame'];
                    if (is_string($frame)) {
                        // A truncated frame: never produced by an SDK, it only shows the server answer.
                        break;
                    }
                    $frame = Kit::obj($frame);
                    if (($frame['action'] ?? null) === 'ping') {
                        self::assertSame(['action' => 'ping', 't' => $frame['t']], $frame);
                        break;
                    }
                    $built = $this->buildSubscribe($frame, $raw);
                    if ($built === null) {
                        // Only frames with a key no SDK may send (a malformed ping, a v2 key, include on raw).
                        self::assertContains($id, self::REFUSED_LOCALLY, $where . ': the builder refused a valid subscribe');
                        ++$refused;
                        break;
                    }
                    self::assertSame(Wire::canonical($frame), Wire::canonical(json_decode($built, true)), $where);
                    if ($lastSubscribe !== null && ($frame['clientNonce'] ?? null) === (Kit::obj(json_decode($lastSubscribe, true))['clientNonce'] ?? null)) {
                        self::assertSame($lastSubscribe, $built, $where . ': the same subscribe after a reconnect');
                    }
                    $lastSubscribe = $built;
                    break;
                case 'serverSends':
                    $frame = $step['frame'];
                    $model->serverSends(is_string($frame) ? $frame : Kit::json($frame));
                    break;
                case 'serverCloses':
                    $model->serverCloses();
                    break;
                case 'advance':
                    $ms = $step['ms'];
                    self::assertIsInt($ms);
                    $model->advance($ms);
                    break;
                case 'expect':
                    $this->expect($step, $model, $where);
                    $afterExpect = true;
                    break;
                default:
                    self::fail('unknown step ' . Kit::str($step['step']));
            }
        }

        self::assertSame(in_array($id, self::REFUSED_LOCALLY, true) ? 1 : 0, $refused);
        if ($id === 'S-14') {
            $this->surfaceAfterConsecutive($config);
        }
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function buildSubscribe(array $frame, bool $raw): ?string
    {
        $params = $frame;
        unset($params['payloadVersion'], $params['clientNonce']);
        $nonce = isset($frame['clientNonce']) ? Kit::str($frame['clientNonce']) : null;
        try {
            return $raw ? Subscribe::raw($params, $nonce) : Subscribe::enriched($params, $nonce);
        } catch (FinlightValidationException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $step
     */
    private function expect(array $step, SessionModel $model, string $where): void
    {
        $outcomes = $model->outcomes;
        $of = static fn (string $kind): array => array_values(array_filter($outcomes, static fn (array $o): bool => $o['outcome'] === $kind));
        $failures = array_merge($of('terminalError'), $of('reconnect'));
        $context = $where . ' ' . json_encode(array_map(static fn ($o) => [$o['outcome'], $o['reason'] ?? null], $outcomes));

        switch ($step['outcome']) {
            case 'admitted':
                $admitted = $of('admitted');
                self::assertNotEmpty($admitted, $context);
                $info = $admitted[count($admitted) - 1]['info'];
                self::assertInstanceOf(\Finlight\AdmittedInfo::class, $info);
                foreach (['leaseId', 'clientNonce'] as $key) {
                    if (array_key_exists($key, $step)) {
                        self::assertSame($step[$key], $info->{$key}, $context);
                    }
                }
                self::assertIsInt($info->serverNow);
                self::assertSame([], $failures, $context);
                break;
            case 'noAdmitted':
                self::assertSame([], $of('admitted'), $context);
                self::assertSame([], $failures, $context);
                break;
            case 'liveness':
                self::assertNotEmpty($of('liveness'), $context);
                self::assertSame([], $failures, $context);
                break;
            case 'item':
                $items = $of('item');
                self::assertNotEmpty($items, $context);
                $item = $items[count($items) - 1];
                self::assertSame($step['kind'], $item['kind'], $context);
                if (isset($step['id'])) {
                    self::assertSame($step['id'], $item['id'], $context);
                }
                $article = $item['article'];
                self::assertInstanceOf(\Finlight\Article::class, $article);
                if ($model->connected && str_contains($where, 'S-17')) {
                    self::assertFalse($article->contentPresent());
                    self::assertFalse($article->sentimentPresent());
                    self::assertFalse($article->companiesPresent());
                    self::assertFalse($article->eventsPresent());
                    self::assertNull($article->availableAt);
                }
                self::assertSame([], $failures, $context);
                break;
            case 'duplicateDropped':
                $dropped = $of('duplicateDropped');
                self::assertNotEmpty($dropped, $context);
                self::assertSame($step['id'], $dropped[count($dropped) - 1]['id']);
                self::assertSame([], $of('item'), $context);
                break;
            case 'logOnly':
                self::assertNotEmpty($of('logOnly'), $context);
                if (isset($step['code'])) {
                    self::assertSame($step['code'], end($outcomes)['code'] ?? null, $context);
                }
                self::assertSame([], $failures, $context);
                break;
            case 'reported':
                $reported = array_values(array_filter($of('reported'), static fn ($o) => $o['hook'] === $step['hook']));
                self::assertNotEmpty($reported, $context);
                $this->checkReported($reported[count($reported) - 1], $step, $context);
                self::assertSame([], $of('terminalError'), $context . ': the stream keeps running');
                break;
            case 'terminalError':
                $terminal = $of('terminalError');
                self::assertCount(1, $terminal, $context);
                $this->checkError($terminal[0]['error'], $step, $context);
                self::assertTrue($model->ended);
                if (str_starts_with($where, 'S-29 ')) {
                    // The SDK does not check vocabularies: the server refuses the unknown value and
                    // the issue names the field and its allowed values.
                    $error = $terminal[0]['error'];
                    self::assertInstanceOf(FinlightApiException::class, $error);
                    $issue = $error->errors()[0];
                    self::assertSame('sourceTypes', $issue->field, $context);
                    self::assertSame(\Finlight\SourceType::ALL, $issue->allowedValues, $context);
                }
                break;
            case 'noReconnect':
                self::assertSame([], $of('reconnect'), $context);
                break;
            case 'reconnect':
                $reconnects = $of('reconnect');
                self::assertNotEmpty($reconnects, $context);
                $r = $reconnects[count($reconnects) - 1];
                self::assertSame([], $of('terminalError'), $context);
                if (isset($step['reason'])) {
                    self::assertSame($step['reason'], $r['reason'], $context);
                }
                $backoff = $step['backoff'] ?? null;
                if ($backoff === true) {
                    self::assertGreaterThan(0, $r['delayMs'] ?? 0, $context . ': reconnect with backoff');
                } elseif ($backoff === 'long') {
                    self::assertGreaterThanOrEqual(60000, $r['delayMs'] ?? 0, $context);
                } elseif ($backoff === 'quota') {
                    self::assertSame('quota', $r['backoff'] ?? null, $context);
                }
                if (isset($step['firstRetryMinMs'])) {
                    self::assertGreaterThanOrEqual($step['firstRetryMinMs'], $r['delayMs'] ?? 0, $context);
                }
                if (isset($step['hint'])) {
                    self::assertSame($step['hint'], $r['hint'] ?? null, $context);
                }
                if (isset($step['reported'])) {
                    $want = Kit::obj($step['reported']);
                    $reported = array_values(array_filter($of('reported'), static fn ($o) => $o['hook'] === $want['hook']));
                    self::assertNotEmpty($reported, $context);
                    $this->checkReported($reported[count($reported) - 1], $want, $context);
                }
                break;
            default:
                self::fail('unknown outcome ' . Kit::str($step['outcome']));
        }
    }

    /**
     * @param array<string, mixed> $reported
     * @param array<string, mixed> $want
     */
    private function checkReported(array $reported, array $want, string $context): void
    {
        $this->checkError($reported['error'], $want, $context);
        if (isset($want['attempt'])) {
            self::assertSame($want['attempt'], $reported['attempt'], $context);
        }
        if ($want['hook'] === 'onQuotaWait') {
            self::assertSame(3_600_000, $reported['nextAttemptInMs'], $context . ': one hour by default');
        }
    }

    /**
     * @param array<string, mixed> $want
     */
    private function checkError(mixed $error, array $want, string $context): void
    {
        if ($want['error'] === 'PreemptedError') {
            // PHP has no PreemptedError: Frame::decode() gives the data as PreemptedInfo.
            self::assertInstanceOf(PreemptedInfo::class, $error, $context);
            self::assertSame($want['reason'], $error->reason, $context);
            self::assertSame($want['newLeaseId'], $error->newLeaseId, $context);

            return;
        }
        self::assertSame('FinlightApiError', $want['error']);
        self::assertInstanceOf(FinlightApiException::class, $error, $context);
        self::assertSame($want['apiKind'], $error->apiKind(), $context);
        if (isset($want['status'])) {
            self::assertSame($want['status'], $error->status(), $context);
        }
        if (isset($want['code'])) {
            self::assertSame($want['code'], $error->code(), $context);
            $issues = $error->errors();
            self::assertNotEmpty($issues, $context);
            self::assertNotNull($issues[0]->field);
            self::assertNotEmpty($issues[0]->constraints);
        }
    }

    /** S-14: SEARCH_FAILED is surfaced as an error only after 5 consecutive failures. */
    private function surfaceAfterConsecutive(FinlightConfig $config): void
    {
        $model = new SessionModel($config);
        $frame = Kit::json(['event' => 'connection.error', 'payloadVersion' => 3, 'data' => ['code' => 'SEARCH_FAILED', 'message' => 'x']]);
        for ($n = 1; $n <= 5; ++$n) {
            $model->dialed();
            $model->outcomes = [];
            $model->serverSends($frame);
            $model->serverCloses();
            $reported = array_filter($model->outcomes, static fn ($o) => $o['outcome'] === 'reported');
            self::assertCount($n < 5 ? 0 : 1, $reported, 'failure ' . $n);
            self::assertNotEmpty(array_filter($model->outcomes, static fn ($o) => $o['outcome'] === 'reconnect'));
        }
    }
}
