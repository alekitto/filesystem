<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Exception;

use RuntimeException;
use Throwable;

use function sprintf;

class InvalidPathException extends RuntimeException implements ExceptionInterface
{
    public function __construct(string $path, Throwable|null $previous = null)
    {
        parent::__construct(sprintf('Invalid path "%s"', $path), 0, $previous);
    }
}
