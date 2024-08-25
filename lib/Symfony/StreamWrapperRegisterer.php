<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Symfony;

use Kcs\Filesystem\Filesystem;
use Kcs\Filesystem\StreamWrapper\StreamWrapper;

class StreamWrapperRegisterer
{
    /** @param array<string, Filesystem> $filesystems */
    public function __construct(private readonly array $filesystems)
    {
    }

    public function register(): void
    {
        foreach ($this->filesystems as $protocol => $filesystem) {
            StreamWrapper::register($protocol, $filesystem);
        }
    }
}
