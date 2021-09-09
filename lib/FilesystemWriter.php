<?php

declare(strict_types=1);

namespace Kcs\Filesystem;

use Kcs\Stream\ReadableStream;

interface FilesystemWriter
{
    /**
     * Write a file to the current filesystem.
     *
     * @param string | ReadableStream $contents
     * @param array<string, mixed> $config
     */
    public function write(string $location, $contents, array $config = []): void;

    /**
     * Deletes a file.
     */
    public function delete(string $location): void;

    /**
     * Removes a directory.
     */
    public function deleteDirectory(string $location): void;

    /**
     * Creates a directory.
     *
     * @param array<string, mixed> $config
     */
    public function createDirectory(string $location, array $config = []): void;

    /**
     * Move a file (moves in the same filesystem).
     *
     * @param array<string, mixed> $config
     * @phpstan-param array{overwrite?: bool, local?: array{dir_permissions?: int, file_permissions?: int}} $config
     */
    public function move(string $source, string $destination, array $config = []): void;

    /**
     * Copy a file (copy into the same filesystem).
     *
     * @param array<string, mixed> $config
     * @phpstan-param array{overwrite?: bool, local?: array{dir_permissions?: int, file_permissions?: int}} $config
     */
    public function copy(string $source, string $destination, array $config = []): void;
}
