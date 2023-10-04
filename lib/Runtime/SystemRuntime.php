<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Runtime;

use function chmod;
use function copy;
use function error_clear_last;
use function error_get_last;
use function fclose;
use function fopen;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function mkdir;
use function rename;
use function rmdir;
use function unlink;

class SystemRuntime implements RuntimeInterface
{
    public function isFile(string $filename): bool
    {
        return ! is_link($filename) && is_file($filename);
    }

    public function isDir(string $filename): bool
    {
        return is_dir($filename);
    }

    public function isLink(string $filename): bool
    {
        return is_link($filename);
    }

    public function isReadable(string $filename): bool
    {
        return is_readable($filename);
    }

    /** @return false|resource */
    public function fopen(string $filename, string $mode)
    {
        return @fopen($filename, $mode);
    }

    /** @param resource $handle */
    public function fclose($handle): void
    {
        @fclose($handle);
    }

    public function chmod(string $filename, int $permissions): bool
    {
        return @chmod($filename, $permissions);
    }

    public function unlink(string $filename): bool
    {
        return @unlink($filename);
    }

    public function rmdir(string $directory): bool
    {
        return @rmdir($directory);
    }

    public function mkdir(string $directory, int $permissions, bool $recursive): bool
    {
        return @mkdir($directory, $permissions, $recursive);
    }

    public function rename(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    public function copy(string $from, string $to): bool
    {
        return @copy($from, $to);
    }

    public function clearLastError(): void
    {
        error_clear_last();
    }

    /** @return array<string, mixed>|null */
    public function getLastError(): array|null
    {
        return error_get_last();
    }
}
