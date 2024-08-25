<?php

declare(strict_types=1);

namespace Kcs\Filesystem\StreamWrapper\Command;

use Kcs\Filesystem\StreamWrapper\Stream;
use Kcs\Stream\ReadableStream;

use function assert;

use const SEEK_SET;

final class StreamSeekCommand
{
    public static function exec(Stream $current, int $offset, int $whence = SEEK_SET): bool
    {
        if (! isset($current->handle)) {
            return false;
        }

        assert($current->handle instanceof ReadableStream);

        return $current->handle->seek($offset, $whence);
    }
}
