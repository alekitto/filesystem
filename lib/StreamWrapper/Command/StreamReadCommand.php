<?php

declare(strict_types=1);

namespace Kcs\Filesystem\StreamWrapper\Command;

use Kcs\Filesystem\StreamWrapper\Stream;
use Kcs\Stream\ReadableStream;

use function assert;

final class StreamReadCommand
{
    public static function exec(Stream $current, int $count): string
    {
        if ($current->writeOnly || ! isset($current->handle) || $count < 0) {
            return '';
        }

        assert($current->handle instanceof ReadableStream);

        return $current->handle->read($count);
    }
}
