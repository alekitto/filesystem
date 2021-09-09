<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Exception;

use RuntimeException;
use Throwable;

class OperationException extends RuntimeException implements ExceptionInterface
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct(
            $message . ($previous !== null ? ': ' . $previous->getMessage() : ''),
            0,
            $previous
        );
    }
}
