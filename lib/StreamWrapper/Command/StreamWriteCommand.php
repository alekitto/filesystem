<?php

declare(strict_types=1);

namespace Kcs\Filesystem\StreamWrapper\Command;

use Kcs\Filesystem\StreamWrapper\Stream;
use Kcs\Stream\WritableStream;

use function assert;
use function method_exists;
use function strlen;

use const SEEK_END;
use const SEEK_SET;

final class StreamWriteCommand
{
    public static function exec(Stream $current, string $data): int
    {
        if (! isset($current->handle)) {
            return 0;
        }

        assert($current->handle instanceof WritableStream);
        if ($current->alwaysAppend && method_exists($current->handle, 'seek')) {
            $current->handle->seek(0, SEEK_END);
        }

        $current->handle->write($data);
        $current->bytesWritten += $size = strlen($data);

        if ($current->alwaysAppend && method_exists($current->handle, 'seek')) {
            $current->handle->seek(0, SEEK_SET);
        }

        return $size;
    }
}
