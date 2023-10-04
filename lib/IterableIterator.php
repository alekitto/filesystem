<?php

declare(strict_types=1);

namespace Kcs\Filesystem;

use Iterator;
use ReturnTypeWillChange;

class IterableIterator implements Iterator
{
    /** @var Iterator<mixed> */
    private Iterator $inner;

    /** @param iterable<mixed> $iterable */
    public function __construct(iterable $iterable)
    {
        $this->inner = (static fn () => yield from $iterable)();
    }

    #[ReturnTypeWillChange]
    public function current(): mixed
    {
        return $this->inner->current();
    }

    public function next(): void
    {
        $this->inner->next();
    }

    #[ReturnTypeWillChange]
    public function key(): mixed
    {
        return $this->inner->key();
    }

    public function valid(): bool
    {
        return $this->inner->valid();
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }
}
