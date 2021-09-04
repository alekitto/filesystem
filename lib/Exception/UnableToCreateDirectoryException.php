<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Exception;

use RuntimeException;
use Throwable;

use function sprintf;

class UnableToCreateDirectoryException extends RuntimeException implements ExceptionInterface
{
    public function __construct(string $path = '', ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Unable to create directory "%s": %s', $path, $previous !== null ? $previous->getMessage() : 'Unknown error'),
            0,
            $previous
        );
    }
}
