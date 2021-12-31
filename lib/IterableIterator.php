<?php

declare(strict_types=1);

namespace Kcs\Filesystem;

use Iterator;

class IterableIterator implements Iterator
{
    /** @var Iterator<mixed> */
    private Iterator $inner;

    /**
     * @param iterable<mixed> $iterable
     */
    public function __construct(iterable $iterable)
    {
        $this->inner = (static fn () => yield from $iterable)();
    }

    /**
     * @return mixed
     */
    public function current()
    {
        return $this->inner->current();
    }

    public function next(): void
    {
        $this->inner->next();
    }

    /**
     * @return mixed
     */
    public function key()
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
