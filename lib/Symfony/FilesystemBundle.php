<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Symfony;

use Kcs\Filesystem\StreamWrapper\StreamWrapper;
use Symfony\Component\HttpKernel\Bundle\Bundle;

use function assert;

class FilesystemBundle extends Bundle
{
    public function boot(): void
    {
        $registerer = $this->container?->get('kcs_filesystem.stream_wrapper_registerer');
        assert($registerer instanceof StreamWrapperRegisterer);

        $registerer->register();
    }

    public function shutdown(): void
    {
        StreamWrapper::unregisterAll();
    }
}
