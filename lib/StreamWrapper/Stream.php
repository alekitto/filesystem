<?php

declare(strict_types=1);

namespace Kcs\Filesystem\StreamWrapper;

use Kcs\Filesystem\Filesystem;
use Kcs\Filesystem\Visibility;
use Kcs\Stream\Stream as FilesystemStream;

use function strpos;
use function substr;

final class Stream
{
//    public Key $lockKey;
//
//    /** @var Iterator<mixed, StorageAttributes> */
//    public Iterator $dirListing;

    public string $path;
    public string $protocol;
    public string $file;
    public Filesystem $filesystem;

    /** @var array{lock_store: string, lock_ttl: int, ignore_visibility_errors: bool, emulate_directory_last_modified: bool, uid: int|null, gid: int|null, visibility_file_public: int, visibility_file_private: int, visibility_directory_public: int, visibility_directory_private: int, visibility_default_for_directories: Visibility} */
    public array $config;

    public FilesystemStream $handle;
    public bool $writeOnly = false;
    public bool $alwaysAppend = false;
    public bool $workOnLocalCopy = false;
    public int $writeBufferSize = 0;
    public int $bytesWritten = 0;

    public function setPath(string $path): void
    {
        $this->path = $path;
        $this->protocol = substr($path, 0, (int) strpos($path, '://'));
        $this->file = self::getFile($path);
        $this->filesystem = StreamWrapper::$filesystems[$this->protocol];
        $this->config = StreamWrapper::$config[$this->protocol];
    }

    public static function getFile(string $path): string
    {
        return (string) substr($path, strpos($path, '://') + 3);
    }

    public function ignoreVisibilityErrors(): bool
    {
        return (bool) $this->config[StreamWrapper::IGNORE_VISIBILITY_ERRORS];
    }

    public function emulateDirectoryLastModified(): bool
    {
        return (bool) $this->config[StreamWrapper::EMULATE_DIRECTORY_LAST_MODIFIED];
    }
}
