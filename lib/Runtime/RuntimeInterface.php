<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Runtime;

interface RuntimeInterface
{
    public function isFile(string $filename): bool;

    public function isDir(string $filename): bool;

    public function isLink(string $filename): bool;

    public function isReadable(string $filename): bool;

    /** @return false|resource */
    public function fopen(string $filename, string $mode);

    /** @param resource $handle */
    public function fclose($handle): void;

    public function chmod(string $filename, int $permissions): bool;

    public function unlink(string $filename): bool;

    public function rmdir(string $directory): bool;

    public function mkdir(string $directory, int $permissions, bool $recursive): bool;

    public function rename(string $from, string $to): bool;

    public function copy(string $from, string $to): bool;

    public function clearLastError(): void;

    /**
     * @return array<string, mixed>|null
     * @phpstan-return array{message?: string, type?: int, file?: string, line?: int}
     */
    public function getLastError(): array|null;
}
