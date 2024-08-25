<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Local\UnixVisibility;

use Kcs\Filesystem\Visibility;

interface VisibilityConverter
{
    public function forFile(Visibility $visibility): int;

    public function forDirectory(Visibility $visibility): int;

    public function inverseForFile(int $visibility): Visibility;

    public function inverseForDirectory(int $visibility): Visibility;

    public function defaultForDirectories(): int;
}
