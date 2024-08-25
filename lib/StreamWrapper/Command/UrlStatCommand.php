<?php

declare(strict_types=1);

namespace Kcs\Filesystem\StreamWrapper\Command;

use Kcs\Filesystem\StreamWrapper\Stream;
use Kcs\Stream\Exception\StreamError;

use function trigger_error;

use const E_USER_WARNING;
use const STREAM_URL_STAT_QUIET;

final class UrlStatCommand
{
    public static function exec(Stream $current, string $path, int $flags): mixed
    {
        $current->setPath($path);

        try {
            return StreamStatCommand::getStat($current);
        } catch (StreamError $e) {
            if (($flags & STREAM_URL_STAT_QUIET) === 0) {
                trigger_error('stat failed: ' . $e->getMessage(), E_USER_WARNING);
            }

            return false;
        }
    }
}
