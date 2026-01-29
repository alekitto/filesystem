<?php

declare(strict_types=1);

namespace Tests\Local;

use Kcs\Filesystem\Local\LocalFilesystem;
use PHPUnit\Framework\TestCase;

class LocalFilesystemIntegrationTest extends TestCase
{
    public function testListDefaultIsShallow(): void
    {
        $base = sys_get_temp_dir() . '/kcs_fs_list_' . uniqid();
        mkdir($base);
        file_put_contents($base . '/root.txt', 'root');
        mkdir($base . '/sub');
        file_put_contents($base . '/sub/nested.txt', 'nested');

        $fs = new LocalFilesystem($base);
        $items = $fs->list('');
        $paths = array_map(static fn ($stat) => $stat->path(), $items->toArray());

        self::assertContains('/root.txt', $paths);
        self::assertContains('/sub', $paths);
        self::assertNotContains('/sub/nested.txt', $paths);

        unlink($base . '/sub/nested.txt');
        rmdir($base . '/sub');
        unlink($base . '/root.txt');
        rmdir($base);
    }

    public function testListDeepIncludesNested(): void
    {
        $base = sys_get_temp_dir() . '/kcs_fs_list_deep_' . uniqid();
        mkdir($base);
        mkdir($base . '/sub');
        file_put_contents($base . '/sub/nested.txt', 'nested');

        $fs = new LocalFilesystem($base);
        $items = $fs->list('', true);
        $paths = array_map(static fn ($stat) => $stat->path(), $items->toArray());

        self::assertContains('/sub/nested.txt', $paths);

        unlink($base . '/sub/nested.txt');
        rmdir($base . '/sub');
        rmdir($base);
    }

    public function testListQuotesPrefixPattern(): void
    {
        $base = sys_get_temp_dir() . '/kcs#fs_' . uniqid();
        mkdir($base);
        file_put_contents($base . '/file.txt', 'data');

        $fs = new LocalFilesystem($base);
        $items = $fs->list('');
        $paths = array_map(static fn ($stat) => $stat->path(), $items->toArray());

        self::assertContains('/file.txt', $paths);

        unlink($base . '/file.txt');
        rmdir($base);
    }
}
