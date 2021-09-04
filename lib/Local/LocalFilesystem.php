<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Local;

use DirectoryIterator;
use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use ErrorException;
use FilesystemIterator;
use Kcs\Filesystem\Exception\OperationException;
use Kcs\Filesystem\Exception\UnableToCreateDirectoryException;
use Kcs\Filesystem\FileStatInterface;
use Kcs\Filesystem\Filesystem;
use Kcs\Filesystem\PathNormalizer;
use Kcs\Stream\BufferStream;
use Kcs\Stream\ReadableStream;
use Kcs\Stream\ResourceStream;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function assert;
use function chmod;
use function clearstatcache;
use function copy;
use function dirname;
use function error_clear_last;
use function error_get_last;
use function fopen;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_readable;
use function is_string;
use function mkdir;
use function rename;
use function rmdir;
use function rtrim;
use function sprintf;
use function unlink;

use const DIRECTORY_SEPARATOR;

class LocalFilesystem implements Filesystem
{
    private string $prefix;
    /**
     * @var array<string, mixed>
     * @phpstan-var array{file_permissions: int, dir_permissions: int}
     */
    private array $defaultConfig;

    /**
     * @param array<string, mixed> $defaultConfig
     * @phpstan-param array{file_permissions?: int, dir_permissions?: int} $defaultConfig
     */
    public function __construct(string $location, array $defaultConfig)
    {
        $this->prefix = rtrim($location, DIRECTORY_SEPARATOR);
        $this->ensureDirectoryExists($this->prefix);

        $defaultConfig['file_permissions'] ??= 0644;
        $defaultConfig['dir_permissions'] ??= 0755;
        $this->defaultConfig = $defaultConfig;
    }

    public function exists(string $location): bool
    {
        $location = $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($location);

        return is_file($location) || is_dir($location) || is_link($location);
    }

    public function read(string $location): ReadableStream
    {
        $path = $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($location);
        if (! is_file($path) || ! is_readable($path)) {
            throw new OperationException(sprintf('File "%s" does not exist or is not readable', $location));
        }

        error_clear_last();
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $previous = $this->exceptionFromError();

            throw new OperationException(sprintf('File "%s" cannot be opened for read: %s', $location, $previous->getMessage()), 0, $previous);
        }

        return new ResourceStream($handle);
    }

    /**
     * @return Collection<FileStatInterface>
     */
    public function list(string $location, bool $deep = false): Collection
    {
        $location = $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($location);
        if (! is_dir($location)) {
            return new ArrayCollection();
        }

        $iterator = $deep
            ? $this->getRecursiveDirectoryIterator($location)
            : new DirectoryIterator($location);

        return new class ($iterator) extends AbstractLazyCollection {
            /** @var iterable<SplFileInfo> */
            private iterable $iterator;

            /**
             * @param iterable<SplFileInfo> $iterator
             */
            public function __construct(iterable $iterator)
            {
                $this->iterator = $iterator;
            }

            protected function doInitialize(): void
            {
                $this->collection = new ArrayCollection();

                foreach ($this->iterator as $fileInfo) {
                    $this->collection[] = new LocalFileStat($fileInfo);
                }
            }
        };
    }

    public function stat(string $path): FileStatInterface
    {
        $location = $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($path);
        $fileInfo = new SplFileInfo($location);

        return new LocalFileStat($fileInfo);
    }

    /**
     * @param string | ReadableStream $contents
     * @param array<string, mixed> $config
     * @phpstan-param array{local?: array{file_permissions?: int}} $config
     */
    public function write(string $location, $contents, array $config = []): void
    {
        $path = $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($location);

        error_clear_last();
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            error_clear_last();
            $handle = @fopen($path, 'wb');

            if (isset($config['local']['file_permissions']) && is_int($config['local']['file_permissions'])) {
                @chmod($path, $config['local']['file_permissions']);
            }
        } else {
            $permissions = $config['local']['file_permissions'] ?? $this->defaultConfig['file_permissions'];
            @chmod($path, $permissions);
        }

        if ($handle === false) {
            $previous = $this->exceptionFromError();

            throw new OperationException(sprintf('Unable to open file "%s" for writing: %s', $location, $previous->getMessage()), 0, $previous);
        }

        $stream = new ResourceStream($handle);
        if (is_string($contents)) {
            $contentStream = new BufferStream();
            $contentStream->write($contents);
            $contentStream->rewind();

            $contents = $contentStream;
        }

        assert($contents instanceof ReadableStream);
        while (! $contents->eof()) {
            $stream->write($contents->read(512));
        }
    }

    public function delete(string $location): void
    {
        $path = $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($location);
        if (! is_file($path) && ! is_link($path)) {
            throw new OperationException(sprintf('Cannot remove file "%s": not a file', $location));
        }

        error_clear_last();
        if (@unlink($path) === false) {
            $previous = $this->exceptionFromError();

            throw new OperationException(sprintf('Cannot remove "%s": %s', $location, $previous->getMessage()), 0, $previous);
        }
    }

    public function deleteDirectory(string $location): void
    {
        $path = $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($location);
        if (! is_dir($path)) {
            return;
        }

        $iterator = $this->getRecursiveDirectoryIterator($location, RecursiveIteratorIterator::CHILD_FIRST);
        error_clear_last();

        foreach ($iterator as $file) {
            assert($file instanceof SplFileInfo);
            $result = @unlink($file->getPathname());
            if ($result === false) {
                $previous = $this->exceptionFromError();

                throw new OperationException(sprintf('Unable to delete directory: unable to delete file "%s": %s', $file->getPathname(), $previous->getMessage()), 0, $previous);
            }
        }

        unset($iterator);

        error_clear_last();
        if (! @rmdir($location)) {
            $previous = $this->exceptionFromError();

            throw new OperationException(sprintf('Unable to delete directory: "%s": %s', $location, $previous->getMessage()), 0, $previous);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @phpstan-param array{local?: array{dir_permissions?: int}} $config
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $path = $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($location);
        if (is_dir($path)) {
            if (isset($config['local']['dir_permissions']) && is_int($config['local']['dir_permissions'])) {
                @chmod($path, $config['local']['dir_permissions']);
            }

            return;
        }

        error_clear_last();
        $result = @mkdir($path, $config['local']['dir_permissions'] ?? $this->defaultConfig['dir_permissions'], true);

        if ($result === false) {
            $previous = $this->exceptionFromError();

            throw new OperationException(sprintf('Unable to create directory: %s', $previous->getMessage()), 0, $previous);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @phpstan-param array{local?: array{dir_permissions?: int}} $config
     */
    public function move(string $source, string $destination, array $config = []): void
    {
        $sourcePath = $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($source);
        $destinationPath = $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($destination);
        $this->ensureDirectoryExists(
            dirname($destinationPath),
            ['local' => ['dir_permissions' => $config['local']['dir_permissions'] ?? $this->defaultConfig['dir_permissions']]]
        );

        if (! @rename($sourcePath, $destinationPath)) {
            $previous = $this->exceptionFromError();

            throw new OperationException(sprintf('Unable to move file: %s', $previous->getMessage()), 0, $previous);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @phpstan-param array{local?: array{dir_permissions?: int}} $config
     */
    public function copy(string $source, string $destination, array $config = []): void
    {
        $sourcePath = $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($source);
        $destinationPath = $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($destination);
        $this->ensureDirectoryExists(
            dirname($destinationPath),
            ['local' => ['dir_permissions' => $config['local']['dir_permissions'] ?? $this->defaultConfig['dir_permissions']]]
        );

        if (! @copy($sourcePath, $destinationPath)) {
            $previous = $this->exceptionFromError();

            throw new OperationException(sprintf('Unable to move file: %s', $previous->getMessage()), 0, $previous);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @phpstan-param array{local?: array{dir_permissions?: int}} $config
     */
    private function ensureDirectoryExists(string $dirname, array $config = []): void
    {
        if (is_dir($dirname)) {
            return;
        }

        error_clear_last();
        $result = @mkdir($dirname, $config['local']['dir_permissions'] ?? $this->defaultConfig['dir_permissions'], true);
        if (! $result) {
            $mkdirError = error_get_last();
        }

        clearstatcache(false, $dirname);

        if (! is_dir($dirname)) {
            $previous = $this->exceptionFromError($mkdirError ?? null);

            throw new UnableToCreateDirectoryException($dirname, $previous);
        }
    }

    /**
     * @param array<string, mixed> $error
     */
    private function exceptionFromError(?array $error = null): ErrorException
    {
        $error ??= error_get_last();

        return new ErrorException(
            $error['message'] ?? '',
            0,
            $error['type'] ?? 1,
            $error['file'] ?? __FILE__,
            $error['line'] ?? __LINE__,
        );
    }

    private function getRecursiveDirectoryIterator(string $location, int $mode = RecursiveIteratorIterator::SELF_FIRST): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(new RecursiveDirectoryIterator($location, FilesystemIterator::SKIP_DOTS), $mode);
    }
}
