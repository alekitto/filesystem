<?php

declare(strict_types=1);

namespace Kcs\Filesystem;

use Doctrine\Common\Collections\Collection;
use Kcs\Stream\ReadableStream;

interface FilesystemReader
{
    /**
     * Whether a file or a directory exists on the current filesystem or not.
     */
    public function exists(string $location): bool;

    /**
     * Reads a file.
     */
    public function read(string $location): ReadableStream;

    /**
     * Lists the content of the specified directory in this filesystem.
     *
     * @return Collection<FileStatInterface>
     */
    public function list(string $location, bool $deep = false): Collection;

    /**
     * Gets the stat object of the specified file or directory.
     */
    public function stat(string $path): FileStatInterface;
}
