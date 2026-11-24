<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that keeps every line, for checks on what the SDK logs.
 */
final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $lines = [];

    /**
     * Untyped $message keeps this compatible with psr/log 1.x to 3.x.
     *
     * @param mixed        $level
     * @param mixed        $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $text = is_string($message) || $message instanceof \Stringable ? (string) $message : get_debug_type($message);
        $this->lines[] = [is_string($level) ? $level : 'other', $text, $context];
    }

    public function text(): string
    {
        return implode("\n", array_map(static fn (array $l): string => $l[0] . ' ' . $l[1] . ' ' . json_encode($l[2]), $this->lines));
    }

    /** @return list<string> */
    public function messages(string $level): array
    {
        $out = [];
        foreach ($this->lines as [$lvl, $msg]) {
            if ($lvl === $level) {
                $out[] = $msg;
            }
        }

        return $out;
    }
}
