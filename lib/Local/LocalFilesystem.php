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
use Kcs\Filesystem\Runtime\RuntimeInterface;
use Kcs\Filesystem\Runtime\SystemRuntime;
use Kcs\Stream\BufferStream;
use Kcs\Stream\ReadableStream;
use Kcs\Stream\ResourceStream;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function assert;
use function clearstatcache;
use function dirname;
use function is_int;
use function is_string;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function rtrim;
use function sprintf;

use const DIRECTORY_SEPARATOR;

class LocalFilesystem implements Filesystem
{
    private RuntimeInterface $runtime;
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
    public function __construct(string $location, array $defaultConfig = [], ?RuntimeInterface $runtime = null)
    {
        $this->runtime = $runtime ?? new SystemRuntime();
        $this->prefix = preg_match('#^[/]+$#', $location) ? '/' : rtrim($location, DIRECTORY_SEPARATOR);

        $defaultConfig['file_permissions'] ??= 0644;
        $defaultConfig['dir_permissions'] ??= 0755;
        $this->defaultConfig = $defaultConfig;
        $this->ensureDirectoryExists($this->prefix);
    }

    public function exists(string $location): bool
    {
        $location = $this->prefix($location);

        return $this->runtime->isFile($location)
            || $this->runtime->isDir($location)
            || $this->runtime->isLink($location);
    }

    public function read(string $location): ReadableStream
    {
        $path = $this->prefix($location);
        if (! $this->runtime->isFile($path) || ! $this->runtime->isReadable($path)) {
            throw new OperationException(sprintf('File "%s" does not exist or is not readable', $location));
        }

        $this->runtime->clearLastError();
        $handle = $this->runtime->fopen($path, 'rb');
        if ($handle === false) {
            $previous = $this->exceptionFromError();

            throw new OperationException(sprintf('File "%s" cannot be opened for read', $location), $previous);
        }

        return new ResourceStream($handle);
    }

    /**
     * @return Collection<FileStatInterface>
     */
    public function list(string $location, bool $deep = false): Collection
    {
        $path = $this->prefix($location);
        if (! $this->runtime->isDir($path)) {
            throw new OperationException(sprintf('Directory "%s" does not exist', $location));
        }

        $iterator = $deep
            ? $this->getRecursiveDirectoryIterator($path)
            : new DirectoryIterator($path);

        return new class ($iterator, $this->prefix ?? '') extends AbstractLazyCollection {
            /** @var iterable<SplFileInfo> */
            private iterable $iterator;
            private string $prefixPattern;

            /**
             * @param iterable<SplFileInfo> $iterator
             */
            public function __construct(iterable $iterator, string $prefix)
            {
                $this->iterator = $iterator;
                $this->prefixPattern = '#^' . preg_quote($prefix, '#') . '#';
            }

            protected function doInitialize(): void
            {
                $this->collection = new ArrayCollection();

                foreach ($this->iterator as $fileInfo) {
                    if ($this->iterator instanceof DirectoryIterator && $this->iterator->isDot()) {
                        continue;
                    }

                    $currentPath = (string) $fileInfo->getRealPath();
                    $currentPath = preg_replace($this->prefixPattern, '', $currentPath);
                    assert($currentPath !== null);

                    $this->collection[] = new LocalFileStat($fileInfo, $currentPath);
                }
            }
        };
    }

    public function stat(string $location): FileStatInterface
    {
        if (! $this->exists($location)) {
            throw new OperationException(sprintf('Stat failed for %s: does not exist', $location));
        }

        $path = $this->prefix($location);
        $fileInfo = new SplFileInfo($path);

        return new LocalFileStat($fileInfo, PathNormalizer::normalizePath($location));
    }

    /**
     * @param string | ReadableStream $contents
     * @param array<string, mixed> $config
     * @phpstan-param array{local?: array{file_permissions?: int}} $config
     */
    public function write(string $location, $contents, array $config = []): void
    {
        $path = $this->prefix($location);

        $this->runtime->clearLastError();
        $handle = $this->runtime->fopen($path, 'xb');
        if ($handle === false) {
            $this->runtime->clearLastError();
            $handle = $this->runtime->fopen($path, 'wb');

            if ($handle !== false && isset($config['local']['file_permissions']) && is_int($config['local']['file_permissions'])) {
                $this->runtime->chmod($path, $config['local']['file_permissions']);
            }
        } else {
            $permissions = $config['local']['file_permissions'] ?? $this->defaultConfig['file_permissions'];
            $this->runtime->chmod($path, $permissions);
        }

        if ($handle === false) {
            $previous = $this->exceptionFromError();

            throw new OperationException(sprintf('Unable to open file "%s" for writing', $location), $previous);
        }

        $stream = new ResourceStream($handle);
        if (is_string($contents)) {
            $contentStream = new BufferStream();
            $contentStream->write($contents);

            $contents = $contentStream;
        }

        assert($contents instanceof ReadableStream);
        while (! $contents->eof()) {
            $stream->write($contents->read(512));
        }

        unset($stream);
        $this->runtime->fclose($handle);
    }

    public function delete(string $location): void
    {
        $path = $this->prefix($location);
        if (! $this->runtime->isFile($path) && ! $this->runtime->isLink($path)) {
            throw new OperationException(sprintf('Cannot remove file "%s": not a file', $location));
        }

        $this->runtime->clearLastError();
        if ($this->runtime->unlink($path) === false) {
            $previous = $this->exceptionFromError();

            throw new OperationException(sprintf('Cannot remove "%s"', $location), $previous);
        }
    }

    public function deleteDirectory(string $location): void
    {
        $path = $this->prefix($location);
        if (! $this->runtime->isDir($path)) {
            throw new OperationException(sprintf('Unable to delete directory "%s": not a directory', $location));
        }

        $iterator = $this->getRecursiveDirectoryIterator($path, RecursiveIteratorIterator::CHILD_FIRST);
        $this->runtime->clearLastError();

        foreach ($iterator as $file) {
            assert($file instanceof SplFileInfo);
            $result = $file->isDir()
                ? $this->runtime->rmdir($file->getPathname())
                : $this->runtime->unlink($file->getPathname());

            if ($result === false) {
                $previous = $this->exceptionFromError();

                throw new OperationException(sprintf('Unable to delete directory: unable to delete file "%s"', $file->getPathname()), $previous);
            }
        }

        unset($iterator);

        $this->runtime->clearLastError();
        if (! $this->runtime->rmdir($path)) {
            $previous = $this->exceptionFromError();

            throw new OperationException(sprintf('Unable to delete directory "%s"', $location), $previous);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @phpstan-param array{local?: array{dir_permissions?: int}} $config
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $path = $this->prefix($location);
        if ($this->runtime->isDir($path)) {
            if (isset($config['local']['dir_permissions']) && is_int($config['local']['dir_permissions'])) {
                $this->runtime->chmod($path, $config['local']['dir_permissions']);
            }

            return;
        }

        $this->runtime->clearLastError();
        $result = $this->runtime->mkdir($path, $config['local']['dir_permissions'] ?? $this->defaultConfig['dir_permissions'], true);

        if ($result === false) {
            $previous = $this->exceptionFromError();

            throw new OperationException('Unable to create directory', $previous);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @phpstan-param array{overwrite?: bool, local?: array{dir_permissions?: int, file_permissions?: int}} $config
     */
    public function move(string $source, string $destination, array $config = []): void
    {
        $sourcePath = $this->prefix($source);
        $destinationPath = $this->prefix($destination);
        if (! $this->exists($sourcePath)) {
            throw new OperationException('Cannot move file: source does not exist');
        }

        $overwrite = $config['overwrite'] ?? false;
        if (! $overwrite && $this->exists($destinationPath)) {
            throw new OperationException('Cannot move file: destination already exist and overwrite flag is not set');
        }

        $this->ensureDirectoryExists(
            dirname($destinationPath),
            ['local' => ['dir_permissions' => $config['local']['dir_permissions'] ?? $this->defaultConfig['dir_permissions']]]
        );

        if (! $this->runtime->rename($sourcePath, $destinationPath)) {
            $previous = $this->exceptionFromError();

            throw new OperationException('Unable to move file', $previous);
        }

        $key = $this->runtime->isDir($destinationPath) ? 'dir_permissions' : 'file_permissions';
        if (! isset($config['local'][$key])) {
            return;
        }

        /** @phpstan-ignore-next-line */
        $this->runtime->chmod($destinationPath, $config['local'][$key]);
    }

    /**
     * @param array<string, mixed> $config
     * @phpstan-param array{local?: array{dir_permissions?: int}, overwrite?: bool} $config
     */
    public function copy(string $source, string $destination, array $config = []): void
    {
        $sourcePath = $this->prefix($source);
        $destinationPath = $this->prefix($destination);
        if (! $this->exists($sourcePath)) {
            throw new OperationException('Cannot copy file: source does not exist');
        }

        $overwrite = $config['overwrite'] ?? false;
        if (! $overwrite && $this->exists($destinationPath)) {
            throw new OperationException('Cannot copy file: destination already exist and overwrite flag is not set');
        }

        $this->ensureDirectoryExists(
            dirname($destinationPath),
            ['local' => ['dir_permissions' => $config['local']['dir_permissions'] ?? $this->defaultConfig['dir_permissions']]]
        );

        if (! $this->runtime->copy($sourcePath, $destinationPath)) {
            $previous = $this->exceptionFromError();

            throw new OperationException('Unable to copy file', $previous);
        }

        $key = $this->runtime->isDir($destinationPath) ? 'dir_permissions' : 'file_permissions';
        if (! isset($config['local'][$key])) {
            return;
        }

        /** @phpstan-ignore-next-line */
        $this->runtime->chmod($destinationPath, $config['local'][$key]);
    }

    /**
     * @param array<string, mixed> $config
     * @phpstan-param array{local?: array{dir_permissions?: int}} $config
     */
    private function ensureDirectoryExists(string $dirname, array $config = []): void
    {
        if ($this->runtime->isDir($dirname)) {
            return;
        }

        $this->runtime->clearLastError();
        $result = $this->runtime->mkdir($dirname, $config['local']['dir_permissions'] ?? $this->defaultConfig['dir_permissions'], true);
        if (! $result) {
            $mkdirError = $this->runtime->getLastError();
        }

        clearstatcache(false, $dirname);

        if (! $this->runtime->isDir($dirname)) {
            $previous = $this->exceptionFromError($mkdirError ?? null);

            throw new UnableToCreateDirectoryException($dirname, $previous);
        }
    }

    /**
     * @param array<string, mixed> $error
     * @phpstan-param array{message?: string, type?: int, file?: string, line?: int} $error
     */
    private function exceptionFromError(?array $error = null): ErrorException
    {
        $error ??= $this->runtime->getLastError();

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

    private function prefix(string $location): string
    {
        $location = preg_replace('#[' . DIRECTORY_SEPARATOR . ']+#', DIRECTORY_SEPARATOR, $this->prefix . DIRECTORY_SEPARATOR . PathNormalizer::normalizePath($location));
        assert(is_string($location));

        return $location !== '/' ? rtrim($location, '/') : $location;
    }
}
