<?php

declare(strict_types=1);

namespace Finlight;

use Finlight\Internal\Json;
use Finlight\Internal\Problem;

/**
 * A decoded WebSocket frame, for your own socket loop.
 *
 *     $frame = Frame::decode($text);
 *     match ($frame->kind()) {
 *         Frame::CREATED, Frame::UPDATED => handle($frame->event()->data),
 *         Frame::PONG => $lastPong = time(),
 *         ...
 *     };
 *
 * Frames are checked for "event" first (v3). A frame with only "action" is a control frame in v2
 * form, which the server sends before the first v3 subscribe of a connection (admit, pong,
 * preempted, error); it is mapped onto the same kinds. A v2 article frame (sendArticle) is never
 * delivered as an article: it decodes as OTHER. Unknown events decode as OTHER, not as an error.
 */
final class Frame
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const OTHER = 'other';
    public const ADMITTED = 'admitted';
    public const PONG = 'pong';
    public const PREEMPTED = 'preempted';
    public const ERROR = 'error';

    /** @var list<string> */
    public const KINDS = [self::CREATED, self::UPDATED, self::OTHER, self::ADMITTED, self::PONG, self::PREEMPTED, self::ERROR];

    /**
     * @param array<string, mixed> $raw
     */
    private function __construct(
        private readonly string $kind,
        private readonly array $raw,
        private readonly ?StreamEvent $event = null,
        private readonly ?AdmittedInfo $admitted = null,
        private readonly ?PreemptedInfo $preempted = null,
        private readonly ?FinlightApiException $error = null,
    ) {
    }

    /**
     * Decode one text frame. Throws FinlightDecodeException when the text is not a JSON object or
     * an article frame carries an article that cannot be read.
     */
    public static function decode(string $text): self
    {
        try {
            $obj = Json::decode($text);
        } catch (\JsonException $e) {
            throw new FinlightDecodeException('finlight: the frame is not valid JSON', null, null, $e);
        }
        if (!Json::isObject($obj) || !Json::looksLikeObject($text)) {
            throw new FinlightDecodeException('finlight: the frame is not a JSON object', null, $obj);
        }

        $event = $obj['event'] ?? null;
        if (is_string($event)) {
            $data = $obj['data'] ?? null;
            $fields = Json::isObject($data) ? $data : [];

            return match ($event) {
                StreamEventName::ARTICLE_CREATED => new self(self::CREATED, $obj, new StreamEvent(StreamEventKind::CREATED, $event, Article::fromJson($data, 'data'), $obj)),
                StreamEventName::ARTICLE_UPDATED => new self(self::UPDATED, $obj, new StreamEvent(StreamEventKind::UPDATED, $event, Article::fromJson($data, 'data'), $obj)),
                StreamEventName::CONNECTION_ADMITTED => new self(self::ADMITTED, $obj, admitted: self::admittedFrom($fields)),
                StreamEventName::CONNECTION_PONG => new self(self::PONG, $obj),
                StreamEventName::CONNECTION_PREEMPTED => new self(self::PREEMPTED, $obj, preempted: self::preemptedFrom($fields)),
                StreamEventName::CONNECTION_ERROR => new self(self::ERROR, $obj, error: Problem::fromConnectionError(
                    self::str($fields['code'] ?? null),
                    self::str($fields['message'] ?? null),
                    $fields['details'] ?? null,
                    $text,
                )),
                default => new self(self::OTHER, $obj, new StreamEvent(StreamEventKind::OTHER, $event, null, $obj)),
            };
        }

        // v2 control frames (R-WS-1): only before the first v3 subscribe of a connection.
        $action = $obj['action'] ?? null;

        return match ($action) {
            'admit' => new self(self::ADMITTED, $obj, admitted: self::admittedFrom($obj)),
            'pong' => new self(self::PONG, $obj),
            'preempted' => new self(self::PREEMPTED, $obj, preempted: self::preemptedFrom($obj)),
            'error' => new self(self::ERROR, $obj, error: Problem::fromConnectionError(null, self::errorText($obj['error'] ?? $obj['data'] ?? null), null, $text)),
            // A v2 sendArticle frame is never delivered as an article.
            default => new self(self::OTHER, $obj, new StreamEvent(StreamEventKind::OTHER, null, null, $obj)),
        };
    }

    /** One of the constants of this class: created, updated, other, admitted, pong, preempted, error. */
    public function kind(): string
    {
        return $this->kind;
    }

    /** The item for created, updated and other; null for the connection kinds. */
    public function event(): ?StreamEvent
    {
        return $this->event;
    }

    /** The data of connection.admitted; null for other kinds. */
    public function admitted(): ?AdmittedInfo
    {
        return $this->admitted;
    }

    /** The data of connection.preempted; null for other kinds. */
    public function preempted(): ?PreemptedInfo
    {
        return $this->preempted;
    }

    /**
     * The error of connection.error as FinlightApiException (no HTTP status): code() is the
     * connection error code (open, ConnectionErrorCode) and errors() carry field and constraints.
     * Null for other kinds.
     */
    public function error(): ?FinlightApiException
    {
        return $this->error;
    }

    /**
     * The whole frame as received.
     *
     * @return array<string, mixed>
     */
    public function rawJson(): array
    {
        return $this->raw;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function admittedFrom(array $fields): AdmittedInfo
    {
        return new AdmittedInfo(self::str($fields['leaseId'] ?? null), self::int($fields['serverNow'] ?? null), self::str($fields['clientNonce'] ?? null));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function preemptedFrom(array $fields): PreemptedInfo
    {
        return new PreemptedInfo(self::str($fields['reason'] ?? null), self::str($fields['newLeaseId'] ?? null), self::int($fields['timestamp'] ?? null));
    }

    private static function errorText(mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        return substr(Json::encode($value), 0, 200);
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
