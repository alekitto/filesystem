<?php

declare(strict_types=1);

namespace Kcs\Filesystem;

use DateTimeInterface;

interface FileStatInterface
{
    /**
     * Gets the file path (called "prefix" on some fs).
     */
    public function path(): string;

    /**
     * Gets the "last modified" date time.
     */
    public function lastModified(): DateTimeInterface;

    /**
     * Gets the file size.
     * If the current object represents a directory, -1 will be returned.
     */
    public function fileSize(): int;

    /**
     * Gets the mime type of the file if available.
     * If the file type is unknown, this will return "application/octet-stream".
     * "application/x-directory" is returned in case $path represents a directory.
     */
    public function mimeType(): string;

    /**
     * Gets the file visibility (private/public).
     */
    public function visibility(): Visibility;
}
