<?php

declare(strict_types=1);

namespace Kcs\Filesystem\StreamWrapper\Helper;

use function file_put_contents;
use function getmygid;
use function getmyuid;
use function stat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class UserGuesser
{
    private static int|null $uid = null;
    private static int|null $gid = null;

    private static function useFallback(): void
    {
        self::$uid = (int) getmyuid();
        self::$gid = (int) getmygid();
    }

    private static function guess(): void
    {
        if (self::$uid !== null) {
            return;
        }

        $file = tempnam(sys_get_temp_dir(), 'UserGuesser');
        if (! $file) {
            self::useFallback();

            return;
        }

        file_put_contents($file, 'guessing');

        $stats = stat($file);
        if (! $stats) {
            self::useFallback();

            return;
        }

        self::$uid = $stats['uid'];
        self::$gid = $stats['gid'];

        unlink($file);
    }

    public static function getUID(): int
    {
        self::guess();

        return (int) self::$uid;
    }

    public static function getGID(): int
    {
        return (int) self::$gid;
    }
}
