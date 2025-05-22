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
        $path = preg_replace('#\p{C}+#u', '', $path);
        assert(is_string($path));
        $path = preg_replace('#^(./)+#', '', $path);
        assert(is_string($path));

        return self::normalizeRelativePath($path);
    }

    private static function normalizeRelativePath(string $path): string
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $path = str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        $newPath = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $pathPart) {
            if ($pathPart === '.' || $pathPart === '') {
                continue;
            }

            if ($pathPart === '..') {
                if (empty($newPath)) {
                    throw new InvalidPathException($path);
                }

                array_pop($newPath);
            } else {
                $newPath[] = $pathPart;
            }
        }

        return implode(DIRECTORY_SEPARATOR, $newPath);
    }
}
