<?php

declare(strict_types=1);

namespace Kcs\Filesystem\StreamWrapper\Command;

use Kcs\Filesystem\StreamWrapper\Stream;
use Kcs\Stream\Exception\OperationException;
use Kcs\Stream\Exception\StreamError;
use Kcs\Stream\ResourceStream;
use Kcs\Stream\WritableStream;

use function assert;
use function fopen;
use function is_resource;
use function method_exists;
use function preg_match;
use function strpos;
use function trigger_error;

use const E_USER_WARNING;
use const STREAM_REPORT_ERRORS;
use const STREAM_USE_PATH;

final class StreamOpenCommand
{
    public static function exec(
        Stream $current,
        string $path,
        string $mode,
        int $options,
        string|null &$openedPath,
    ): bool {
        $current->setPath($path);
        $filesystem = $current->filesystem;
        $file = $current->file;

        if (! preg_match('/^[rwacx](\+b?|b\+?)?$/', $mode)) {
            trigger_error('Invalid mode "' . $mode . '".', E_USER_WARNING);

            return false;
        }

        $current->writeOnly = ! strpos($mode, '+');
        if ($mode[0] === 'r' && $current->writeOnly) {
            $current->handle = $filesystem->read($file);
            $current->workOnLocalCopy = false;
            $current->writeOnly = false;
        } else {
            $resource = fopen('php://temp', 'w+b');
            assert(is_resource($resource));

            try {
                $current->handle = new ResourceStream($resource);
            } catch (StreamError) {
                unset($current->handle);
            }

            $current->workOnLocalCopy = true;

            if ($mode[0] !== 'w' && $filesystem->exists($file)) {
                if (($mode[0] === 'x') && ($options & STREAM_REPORT_ERRORS) !== 0) {
                    trigger_error('File "' . $file . '" already exists.', E_USER_WARNING);
                }

                $result = false;
                if (isset($current->handle)) {
                    assert($current->handle instanceof WritableStream);

                    try {
                        $filesystem->read($file)->pipe($current->handle);
                        $result = true;
                    } catch (StreamError) {
                        // @ignoreException
                    }
                }

                if (! $result) {
                    if (($options & STREAM_REPORT_ERRORS) !== 0) {
                        trigger_error('File "' . $file . '" already exists.', E_USER_WARNING);
                    }

                    return false;
                }
            }
        }

        $current->alwaysAppend = $mode[0] === 'a';
        if (isset($current->handle) && ! $current->alwaysAppend && method_exists($current->handle, 'rewind')) {
            try {
                $current->handle->rewind();
            } catch (OperationException) {
                // Rewind not supported by stream, ignore.
            }
        }

        if (isset($current->handle) && $options & STREAM_USE_PATH) {
            $openedPath = $path;
        }

        if (isset($current->handle)) {
            return true;
        }

        if (($options & STREAM_REPORT_ERRORS) !== 0) {
            trigger_error('File "' . $file . '" not found.', E_USER_WARNING);
        }

        return false;
    }
}
