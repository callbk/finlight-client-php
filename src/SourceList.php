<?php

declare(strict_types=1);

namespace Finlight;

/**
 * The result of sources->list(), sorted by domain.
 *
 * Iterate it with foreach and count it with count(). Entries that could not be read are in
 * skipped() and are left out of the list.
 *
 * @implements \IteratorAggregate<int, Source>
 */
final class SourceList implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Source>     $items
     * @param list<SkippedEntry> $skipped
     */
    public function __construct(private readonly array $items, private readonly array $skipped = [])
    {
    }

    /** @return \ArrayIterator<int, Source> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return list<SkippedEntry> */
    public function skipped(): array
    {
        return $this->skipped;
    }
}
