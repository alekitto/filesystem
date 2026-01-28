<?php

declare(strict_types=1);

namespace Kcs\Filesystem\StreamWrapper\Command;

use Iterator;
use IteratorIterator;
use Kcs\Filesystem\FileStatInterface;
use Kcs\Filesystem\Local\UnixVisibility\PortableVisibilityConverter;
use Kcs\Filesystem\StreamWrapper\Stream;
use Kcs\Filesystem\StreamWrapper\StreamWrapper;
use Kcs\Filesystem\Visibility;
use Kcs\Stream\Exception\StreamError;
use Kcs\Stream\ResourceStream;
use ReflectionClass;
use Throwable;

use function assert;
use function fstat;
use function is_resource;
use function max;
use function method_exists;
use function trigger_error;

use const E_USER_WARNING;

final class StreamStatCommand
{
    /** @return array<int|string,int|string>|false */
    public static function exec(Stream $current): array|false
    {
        try {
            return self::getStat($current);
        } catch (StreamError $e) {
            trigger_error('stat failed:' . $e->getMessage(), E_USER_WARNING);

            return false;
        }
    }

    private const array STATS_ZERO = [0, 'dev', 1, 'ino', 3, 'nlink', 6, 'rdev'];
    private const array STATS_MODE = [2, 'mode'];
    private const array STATS_SIZE = [7, 'size'];
    private const array STATS_TIME = [8, 'atime', 9, 'mtime', 10, 'ctime'];
    private const array STATS_MINUS_ONE = [11, 'blksize', 12, 'blocks'];

    /**
     * @return array<int|string,int|string>|false
     *
     * @throws StreamError
     */
    public static function getStat(Stream $current): array|false
    {
        $stats = [];

        if ($current->workOnLocalCopy && isset($current->handle)) {
            $reflClass = new ReflectionClass(ResourceStream::class);
            $resource = $reflClass->getProperty('resource');
            $resource->setAccessible(true);

            $handle = $resource->getValue($current->handle);
            assert(is_resource($handle));
            $stats = fstat($handle);
            if (! $stats) {
                return false;
            }

            if ($current->filesystem->exists($current->file)) {
                [$mode, $size, $time] = self::getRemoteStats($current);

                unset($size);
            }
        } else {
            [$mode, $size, $time] = self::getRemoteStats($current);
        }

        foreach (self::STATS_ZERO as $key) {
            $stats[$key] = 0;
        }

        foreach (self::STATS_MINUS_ONE as $key) {
            $stats[$key] = -1;
        }

        if (isset($mode)) {
            foreach (self::STATS_MODE as $key) {
                $stats[$key] = $mode;
            }
        }

        if (isset($size)) {
            foreach (self::STATS_SIZE as $key) {
                $stats[$key] = $size;
            }
        }

        if (isset($time)) {
            foreach (self::STATS_TIME as $key) {
                $stats[$key] = $time;
            }
        }

        $stats['uid'] = $stats[4] = (int) $current->config[StreamWrapper::UID];
        $stats['gid'] = $stats[5] = (int) $current->config[StreamWrapper::GID];

        return $stats;
    }

    /**
     * @return array<int,int>
     *
     * @throws StreamError
     */
    public static function getRemoteStats(Stream $current): array
    {
        $converter = new PortableVisibilityConverter(
            (int) $current->config[StreamWrapper::VISIBILITY_FILE_PUBLIC],
            (int) $current->config[StreamWrapper::VISIBILITY_FILE_PRIVATE],
            (int) $current->config[StreamWrapper::VISIBILITY_DIRECTORY_PUBLIC],
            (int) $current->config[StreamWrapper::VISIBILITY_DIRECTORY_PRIVATE],
            $current->config[StreamWrapper::VISIBILITY_DEFAULT_FOR_DIRECTORIES],
        );

        try {
            $stats = $current->filesystem->stat($current->file);
            $visibility = $stats->visibility();
        } catch (Throwable $e) {
            if (! $current->ignoreVisibilityErrors()) {
                throw $e;
            }

            $stats = null;
            $visibility = Visibility::Public;
        }

        $mode = 0;
        $size = 0;
        $lastModified = 0;

        try {
            if ($stats?->mimeType() === 'application/x-directory') {
                [$mode, $size, $lastModified] = self::getRemoteDirectoryStats($current, $converter, $visibility);
            } else {
                [$mode, $size, $lastModified] = self::getRemoteFileStats($current, $converter, $visibility);
            }
        } catch (StreamError $e) {
            if (! method_exists($current->filesystem, 'directoryExists')) {
                throw $e;
            }

            if ($current->filesystem->directoryExists($current->file)) {
                [$mode, $size, $lastModified] = self::getRemoteDirectoryStats($current, $converter, $visibility);
            } elseif ($current->filesystem->exists($current->file)) {
                [$mode, $size, $lastModified] = self::getRemoteFileStats($current, $converter, $visibility);
            }
        }

        return [$mode, $size, $lastModified];
    }

    /** @return int[] */
    private static function getRemoteDirectoryStats(
        Stream $current,
        PortableVisibilityConverter $converter,
        Visibility $visibility,
    ): array {
        $mode = 040000 + $converter->forDirectory($visibility);
        $size = 0;

        $lastModified = self::getRemoteDirectoryLastModified($current);

        return [$mode, $size, $lastModified];
    }

    /** @return int[] */
    private static function getRemoteFileStats(
        Stream $current,
        PortableVisibilityConverter $converter,
        Visibility $visibility,
    ): array {
        $mode = 0100000 + $converter->forFile($visibility);

        $stat = $current->filesystem->stat($current->file);
        $size = $stat->fileSize();
        $lastModified = $stat->lastModified();

        return [$mode, $size, $lastModified->getTimestamp()];
    }

    /** @throws StreamError */
    private static function getRemoteDirectoryLastModified(Stream $current): int
    {
        if (! $current->emulateDirectoryLastModified()) {
            $stat = $current->filesystem->stat($current->file);

            return $stat->lastModified()->getTimestamp();
        }

        $lastModified = 0;
        $listing = $current->filesystem->list($current->file)->getIterator();
        $dirListing = $listing instanceof Iterator ? $listing : new IteratorIterator($listing);

        foreach ($dirListing as $item) {
            assert($item instanceof FileStatInterface);
            $lastModified = max($lastModified, $item->lastModified()->getTimestamp());
        }

        return $lastModified;
    }
}
