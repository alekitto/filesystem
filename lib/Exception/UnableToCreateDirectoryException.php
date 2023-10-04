<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Exception;

use Throwable;

use function sprintf;

class UnableToCreateDirectoryException extends OperationException
{
    public function __construct(string $path = '', Throwable|null $previous = null)
    {
        parent::__construct(
            sprintf('Unable to create directory "%s"', $path),
            $previous,
        );
    }
}
