<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

use Finlight\FinlightApiException;
use Finlight\FinlightConfig;
use Finlight\FinlightNetworkException;
use Finlight\ForbiddenException;
use Finlight\Frame;
use Finlight\InternalServerException;
use Finlight\PreemptedInfo;
use Finlight\RateLimitedException;
use Finlight\RateLimitType;
use Finlight\UnauthorizedException;

/**
 * A reference socket loop without I/O, built only on the public building blocks (Frame::decode,
 * FinlightConfig values) and the connection rules the README gives to customers (R-WS-2, R-WS-4,
 * R-WS-5). The WebSocket sessions of the kit run against it at the decoder level: the test proves
 * that the decoded frames carry everything a loop needs to reach the expected outcome.
 *
 * The reconnect backoff doubles from reconnectBaseDelay up to reconnectMaxDelay and starts over
 * only on a pong or an article, never on connection.admitted (which comes before the subscribe is
 * checked). A planned rotation dials while the old connection is still open; when that dial is
 * refused or fails and the refusal does not end the stream, the loop closes the old connection,
 * waits for its close reply (closeOld) and dials again at once, without a report.
 *
 * Outcomes are arrays with an "outcome" key: admitted, liveness, item, duplicateDropped, logOnly,
 * reported, terminalError, closeOld, reconnect (scheduled: delayMs; or fired: fired=true).
 */
final class SessionModel
{
    /** @var list<array<string, mixed>> */
    public array $outcomes = [];

    /** @var list<int> times (ms) at which the loop sent a ping */
    public array $pings = [];

    public bool $connected = false;

    public bool $ended = false;

    private int $now = 0;

    private int $connectedAt = 0;

    private int $lastPongAt = 0;

    private int $lastPingAt = 0;

    /** @var array<string, true> dedupe keys: event|id|sha256(data) */
    private array $seen = [];

    private int $searchFailed = 0;

    private int $quotaAttempt = 0;

    /** reconnects since the last pong or article */
    private int $attempt = 0;

    /** a planned rotation is dialing; the old connection is still open */
    private bool $rotating = false;

    private bool $decided = false;

    /** @var array{at: int, reason: string}|null */
    private ?array $timer = null;

    public function __construct(private readonly FinlightConfig $config, private readonly bool $autoPong = false)
    {
    }

    public function dialed(): void
    {
        $this->connected = true;
        $this->rotating = false;
        $this->decided = false;
        $this->connectedAt = $this->now;
        $this->lastPongAt = $this->now;
        $this->lastPingAt = $this->now;
        $this->timer = null;
    }

    /**
     * A refused WebSocket upgrade. Most libraries give only the status; the body decides when it
     * is there. 403 texts are compared by their start.
     */
    public function dialRefused(int $status, string $body): void
    {
        $text = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $value = $decoded['message'] ?? $decoded['error'] ?? '';
            $text = is_string($value) ? $value : '';
        }
        if ($this->rotating && !$this->endsTheStream($status, $text)) {
            // Often the connection limit, which the old connection still counts against.
            $this->redialAfterRotation();

            return;
        }
        $this->rotating = false;
        $message = sprintf('finlight: WebSocket dial refused with %d: %s', $status, $text);
        $error = match (true) {
            $status === 401 => new UnauthorizedException($message, $status, null, null, null, $text, null, [], $body),
            $status === 403 => new ForbiddenException($message, $status, null, null, null, $text, null, [], $body),
            $status === 429 => new RateLimitedException($message, $status, null, null, null, $text, null, [], $body, null, RateLimitType::UNKNOWN),
            $status >= 500 => new InternalServerException($message, $status, null, null, null, $text, null, [], $body),
            default => new FinlightApiException($message, $status, null, null, null, $text, null, [], $body),
        };

        if ($status === 401) {
            $this->terminal($error);

            return;
        }
        if ($status === 403) {
            if (str_starts_with($text, 'Your subscription does not include WebSockets')) {
                $this->terminal($error);

                return;
            }
            if (str_starts_with($text, 'WebSocket quota exceeded')) {
                $this->quotaWait($error);

                return;
            }
            if (str_starts_with($text, 'Too many concurrent connections')) {
                $this->schedule('connection_limit', (int) ($this->config->longBackoff * 1000), str_contains($text, 'x-takeover') ? 'takeover' : null);

                return;
            }
            // No readable body: a long wait plus an error report.
            $this->outcomes[] = ['outcome' => 'reported', 'hook' => 'onError', 'error' => $error];
            $this->schedule('dial_refused', (int) ($this->config->longBackoff * 1000));

            return;
        }
        if ($status === 429) {
            $this->schedule('rate_limited', (int) ($this->config->longBackoff * 1000));

            return;
        }
        $this->schedule('dial_failed', $this->backoffMs());
    }

    /** A dial that failed without an HTTP answer (network error, timeout). */
    public function dialFailed(): void
    {
        if ($this->rotating) {
            $this->redialAfterRotation();

            return;
        }
        $this->outcomes[] = ['outcome' => 'reported', 'hook' => 'onError', 'error' => new FinlightNetworkException('finlight: WebSocket dial failed')];
        $this->schedule('dial_failed', $this->backoffMs());
    }

    public function serverSends(string $text): void
    {
        $frame = Frame::decode($text);
        switch ($frame->kind()) {
            case Frame::ADMITTED:
                // Not a backoff reset: admitted comes before the server checks the subscribe.
                $this->outcomes[] = ['outcome' => 'admitted', 'info' => $frame->admitted()];
                $this->lastPongAt = $this->now;
                break;
            case Frame::PONG:
                $this->lastPongAt = $this->now;
                $this->attempt = 0;
                $this->outcomes[] = ['outcome' => 'liveness'];
                break;
            case Frame::CREATED:
            case Frame::UPDATED:
                $event = $frame->event();
                $article = $event?->data;
                if ($event === null || $article === null) {
                    throw new \LogicException('article frame without article');
                }
                $this->searchFailed = 0;
                $this->attempt = 0;
                $key = $event->event . '|' . $article->id . '|' . hash('sha256', Wire::canonical($event->rawJson()['data'] ?? null));
                if (isset($this->seen[$key])) {
                    $this->outcomes[] = ['outcome' => 'duplicateDropped', 'id' => $article->id];
                    break;
                }
                $this->seen[$key] = true;
                $this->outcomes[] = ['outcome' => 'item', 'kind' => $event->kind, 'id' => $article->id, 'article' => $article];
                break;
            case Frame::PREEMPTED:
                $info = $frame->preempted();
                if ($info === null) {
                    throw new \LogicException('preempted frame without data');
                }
                if ($info->reason === 'quota_exceeded') {
                    $this->quotaWait($info);
                } elseif ($info->reason === 'connection_limit') {
                    $this->terminal($info);
                } else {
                    $this->schedule('closed', $this->backoffMs());
                }
                break;
            case Frame::ERROR:
                $error = $frame->error();
                if ($error === null) {
                    throw new \LogicException('error frame without error');
                }
                switch ($error->code()) {
                    case 'VALIDATION_FAILED':
                        $this->terminal($error);
                        break;
                    case 'HANDSHAKE_FAILED':
                        $this->schedule('handshake_failed', $this->backoffMs());
                        break;
                    case 'SEARCH_FAILED':
                        ++$this->searchFailed;
                        if ($this->searchFailed >= $this->config->searchFailedThreshold) {
                            $this->outcomes[] = ['outcome' => 'reported', 'hook' => 'onError', 'error' => $error];
                        }
                        $this->schedule('search_failed', $this->backoffMs());
                        break;
                    default:
                        // PING_ON_DEFAULT_ROUTE, INTERNAL_ERROR, a v2 error, unknown codes: log only.
                        $this->outcomes[] = ['outcome' => 'logOnly', 'code' => $error->code()];
                }
                break;
            default:
                // An unknown event (or a v2 sendArticle): logged and skipped, never an error.
                $this->outcomes[] = ['outcome' => 'logOnly', 'event' => $frame->event()?->event];
        }
    }

    public function serverCloses(): void
    {
        $this->connected = false;
        if (!$this->decided && !$this->ended) {
            $this->schedule('closed', $this->backoffMs());
        }
    }

    public function advance(int $ms): void
    {
        $target = $this->now + $ms;
        $ping = (int) ($this->config->pingInterval * 1000);
        $watchdog = (int) ($this->config->watchdogTimeout * 1000);
        $rotation = (int) ($this->config->rotationInterval * 1000);
        while (true) {
            $next = $target;
            if ($this->connected && !$this->decided) {
                $next = min($next, $this->lastPingAt + $ping, $this->lastPongAt + $watchdog, $this->connectedAt + $rotation);
            }
            if ($this->timer !== null) {
                $next = min($next, $this->timer['at']);
            }
            $this->now = max($this->now, $next);
            if ($this->timer !== null && $this->now >= $this->timer['at']) {
                $reason = $this->timer['reason'];
                $this->timer = null;
                $this->outcomes[] = ['outcome' => 'reconnect', 'fired' => true, 'reason' => $reason];
                $this->connected = false;
                continue;
            }
            if ($this->connected && !$this->decided) {
                if ($this->now >= $this->connectedAt + $rotation) {
                    $this->decide('rotation');
                    continue;
                }
                if ($this->now >= $this->lastPongAt + $watchdog) {
                    $this->decide('watchdog');
                    continue;
                }
                if ($this->now >= $this->lastPingAt + $ping) {
                    $this->pings[] = $this->now;
                    $this->lastPingAt = $this->now;
                    if ($this->autoPong) {
                        $this->lastPongAt = $this->now;
                    }
                    continue;
                }
            }
            if ($this->now >= $target) {
                return;
            }
        }
    }

    /** The ping a loop sends to the ping route. */
    public function pingFrame(): string
    {
        return Kit::json(['action' => 'ping', 't' => $this->now]);
    }

    private function decide(string $reason): void
    {
        $this->decided = true;
        $this->connected = false;
        $this->rotating = $reason === 'rotation';
        $this->outcomes[] = ['outcome' => 'reconnect', 'fired' => true, 'reason' => $reason];
    }

    /** The refusals that end a stream end it during a rotation too. */
    private function endsTheStream(int $status, string $text): bool
    {
        return $status === 401
            || ($status === 403 && str_starts_with($text, 'Your subscription does not include WebSockets'))
            || ($status === 403 && str_starts_with($text, 'WebSocket quota exceeded') && !$this->config->quotaReconnect);
    }

    /** Close the old connection, wait for its close reply (at most 5 s), dial again at once. */
    private function redialAfterRotation(): void
    {
        $this->rotating = false;
        $this->decided = true;
        $this->outcomes[] = ['outcome' => 'closeOld', 'waitForCloseReplyMs' => 5000];
        $this->outcomes[] = ['outcome' => 'reconnect', 'fired' => true, 'reason' => 'rotation'];
    }

    /** Doubles from reconnectBaseDelay up to reconnectMaxDelay; a pong or an article resets it. */
    private function backoffMs(): int
    {
        $delay = (int) min($this->config->reconnectMaxDelay * 1000, $this->config->reconnectBaseDelay * 1000 * 2 ** $this->attempt);
        ++$this->attempt;

        return $delay;
    }

    private function schedule(string $reason, int $delayMs, ?string $hint = null): void
    {
        $this->decided = true;
        $this->timer = ['at' => $this->now + $delayMs, 'reason' => $reason];
        $this->outcomes[] = ['outcome' => 'reconnect', 'reason' => $reason, 'delayMs' => $delayMs, 'hint' => $hint];
    }

    private function quotaWait(FinlightApiException|PreemptedInfo $error): void
    {
        if (!$this->config->quotaReconnect) {
            $this->terminal($error);

            return;
        }
        ++$this->quotaAttempt;
        $delay = (int) ($this->config->quotaReconnectInterval * 1000);
        $this->outcomes[] = ['outcome' => 'reported', 'hook' => 'onQuotaWait', 'error' => $error, 'nextAttemptInMs' => $delay, 'attempt' => $this->quotaAttempt];
        $this->decided = true;
        $this->timer = ['at' => $this->now + $delay, 'reason' => 'quota_exceeded'];
        $this->outcomes[] = ['outcome' => 'reconnect', 'reason' => 'quota_exceeded', 'delayMs' => $delay, 'backoff' => 'quota'];
    }

    private function terminal(FinlightApiException|PreemptedInfo $error): void
    {
        $this->decided = true;
        $this->ended = true;
        $this->connected = false;
        $this->timer = null;
        $this->outcomes[] = ['outcome' => 'terminalError', 'error' => $error];
    }
}
