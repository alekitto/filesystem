<?php

declare(strict_types=1);

namespace Kcs\Filesystem;

use Kcs\Filesystem\Exception\InvalidPathException;

use function array_pop;
use function assert;
use function explode;
use function implode;
use function is_string;
use function preg_replace;
use function str_replace;

use const DIRECTORY_SEPARATOR;

class PathNormalizer
{
    public static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        assert(is_string($path));
        $path = preg_replace('#\p{C}+#u', '', $path);
        assert(is_string($path));
        $path = preg_replace('#^(./)+#', '', $path);
        assert(is_string($path));

        return self::normalizeRelativePath($path);
    }

    private static function normalizeRelativePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if (empty($parts)) {
                    throw new InvalidPathException($path);
                }

                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return implode(DIRECTORY_SEPARATOR, $parts);
    }
}
