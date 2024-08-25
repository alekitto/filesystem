<?php

declare(strict_types=1);

namespace Kcs\Filesystem\StreamWrapper\Command;

use Kcs\Filesystem\StreamWrapper\Stream;
use Kcs\Stream\ReadableStream;

use function assert;

final class StreamEofCommand
{
    public static function exec(Stream $current): bool
    {
        if (! isset($current->handle)) {
            return false;
        }

        assert($current->handle instanceof ReadableStream);

        return $current->handle->eof();
    }
}
