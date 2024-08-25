<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Local\UnixVisibility;

use Kcs\Filesystem\Visibility;

class PortableVisibilityConverter implements VisibilityConverter
{
    public function __construct(
        private readonly int $filePublic = 0644,
        private readonly int $filePrivate = 0600,
        private readonly int $directoryPublic = 0755,
        private readonly int $directoryPrivate = 0700,
        private readonly Visibility $defaultForDirectories = Visibility::Private,
    ) {
    }

    public function forFile(Visibility $visibility): int
    {
        return $visibility === Visibility::Public
            ? $this->filePublic
            : $this->filePrivate;
    }

    public function forDirectory(Visibility $visibility): int
    {
        return $visibility === Visibility::Public
            ? $this->directoryPublic
            : $this->directoryPrivate;
    }

    public function inverseForFile(int $visibility): Visibility
    {
        if ($visibility === $this->filePublic) {
            return Visibility::Public;
        }

        if ($visibility === $this->filePrivate) {
            return Visibility::Private;
        }

        return Visibility::Public; // default
    }

    public function inverseForDirectory(int $visibility): Visibility
    {
        if ($visibility === $this->directoryPublic) {
            return Visibility::Public;
        }

        if ($visibility === $this->directoryPrivate) {
            return Visibility::Private;
        }

        return Visibility::Public; // default
    }

    public function defaultForDirectories(): int
    {
        return $this->defaultForDirectories === Visibility::Public ? $this->directoryPublic : $this->directoryPrivate;
    }
}
