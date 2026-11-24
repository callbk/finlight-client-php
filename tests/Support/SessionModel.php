<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

use Finlight\FinlightApiException;
use Finlight\FinlightConfig;
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
 * Outcomes are arrays with an "outcome" key: admitted, liveness, item, duplicateDropped, logOnly,
 * reported, terminalError, reconnect (scheduled: delayMs; or fired: fired=true).
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

    private bool $decided = false;

    /** @var array{at: int, reason: string}|null */
    private ?array $timer = null;

    public function __construct(private readonly FinlightConfig $config, private readonly bool $autoPong = false)
    {
    }

    public function dialed(): void
    {
        $this->connected = true;
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
        $this->schedule('dial_failed', (int) ($this->config->reconnectBaseDelay * 1000));
    }

    public function serverSends(string $text): void
    {
        $frame = Frame::decode($text);
        switch ($frame->kind()) {
            case Frame::ADMITTED:
                $this->outcomes[] = ['outcome' => 'admitted', 'info' => $frame->admitted()];
                $this->lastPongAt = $this->now;
                break;
            case Frame::PONG:
                $this->lastPongAt = $this->now;
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
                    $this->schedule('closed', (int) ($this->config->reconnectBaseDelay * 1000));
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
                        $this->schedule('handshake_failed', (int) ($this->config->reconnectBaseDelay * 1000));
                        break;
                    case 'SEARCH_FAILED':
                        ++$this->searchFailed;
                        if ($this->searchFailed >= $this->config->searchFailedThreshold) {
                            $this->outcomes[] = ['outcome' => 'reported', 'hook' => 'onError', 'error' => $error];
                        }
                        $this->schedule('search_failed', (int) ($this->config->reconnectBaseDelay * 1000));
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
            $this->schedule('closed', (int) ($this->config->reconnectBaseDelay * 1000));
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
        $this->outcomes[] = ['outcome' => 'reconnect', 'fired' => true, 'reason' => $reason];
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
