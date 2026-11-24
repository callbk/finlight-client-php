<?php

declare(strict_types=1);

namespace Finlight;

/**
 * The result of companies->search() and companies->getMany(). The order is not guaranteed.
 *
 * Iterate it with foreach and count it with count(). Entries that could not be read are in
 * skipped() and are left out of the list.
 *
 * @implements \IteratorAggregate<int, CompanyRecord>
 */
final class CompanyList implements \IteratorAggregate, \Countable
{
    /**
     * @param list<CompanyRecord>     $items
     * @param list<SkippedEntry> $skipped
     */
    public function __construct(private readonly array $items, private readonly array $skipped = [])
    {
    }

    /** @return \ArrayIterator<int, CompanyRecord> */
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
