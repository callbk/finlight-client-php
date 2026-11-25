<?php

declare(strict_types=1);

namespace Finlight;

/**
 * An article frame (or an unknown event) decoded by Frame::decode().
 *
 * kind is created, updated or other (StreamEventKind). event is the wire name (open; null for a
 * frame without one). data is the article for created and updated, null for other. rawJson() gives
 * the whole frame.
 */
final class StreamEvent
{
    /**
     * @param array<string, mixed> $frame
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $event,
        public readonly ?Article $data,
        private readonly array $frame,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function rawJson(): array
    {
        return $this->frame;
    }
}
