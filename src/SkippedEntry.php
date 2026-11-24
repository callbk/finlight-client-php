<?php

declare(strict_types=1);

namespace Finlight;

/**
 * An entry of a list answer that could not be read. It is left out of the result and logged as a
 * warning. index is its position in the answer, raw the entry as received, reason why it failed.
 */
final class SkippedEntry
{
    public function __construct(
        public readonly int $index,
        public readonly mixed $raw,
        public readonly string $reason,
    ) {
    }
}
