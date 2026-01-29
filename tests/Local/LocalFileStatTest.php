<?php

declare(strict_types=1);

namespace Tests\Local;

use Kcs\Filesystem\Local\LocalFileStat;
use PHPUnit\Framework\TestCase;

class LocalFileStatTest extends TestCase
{
    public function testUsesFileMtimeAndSize(): void
    {
        $dir = sys_get_temp_dir() . '/kcs_fs_stat_' . uniqid();
        mkdir($dir);
        $file = $dir . '/file.txt';
        file_put_contents($file, 'hello');
        touch($file, 0);

        $stat = new LocalFileStat(new \SplFileInfo($file), 'file.txt');

        self::assertSame(0, $stat->lastModified()->getTimestamp());
        self::assertSame(5, $stat->fileSize());

        unlink($file);
        rmdir($dir);
    }

    public function testDirectoryHasNegativeSize(): void
    {
        $dir = sys_get_temp_dir() . '/kcs_fs_stat_dir_' . uniqid();
        mkdir($dir);
        touch($dir, 0);

        $stat = new LocalFileStat(new \SplFileInfo($dir), 'dir');

        self::assertSame(-1, $stat->fileSize());
        self::assertSame(0, $stat->lastModified()->getTimestamp());

        rmdir($dir);
    }
}
