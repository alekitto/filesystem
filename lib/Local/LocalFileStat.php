<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Local;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Kcs\Filesystem\FileStatInterface;
use Kcs\Filesystem\Local\UnixVisibility\PortableVisibilityConverter;
use Kcs\Filesystem\Visibility;
use LogicException;
use SplFileInfo;
use Symfony\Component\Mime\MimeTypes;

use function array_key_first;

class LocalFileStat implements FileStatInterface
{
    private DateTimeImmutable $lastModified;
    private int $fileSize;

    public function __construct(private SplFileInfo $fileInfo, private string $relativePath)
    {
        $this->lastModified = new DateTimeImmutable('@' . ($fileInfo->getMTime() ?: 0));
        $this->fileSize = $fileInfo->isDir() ? -1 : $fileInfo->getSize();
    }

    public function path(): string
    {
        return $this->relativePath;
    }

    public function lastModified(): DateTimeInterface
    {
        return $this->lastModified;
    }

    public function fileSize(): int
    {
        return $this->fileSize;
    }

    public function mimeType(): string
    {
        if ($this->fileInfo->isDir()) {
            return 'application/x-directory';
        }

        static $mimeTypes = null;
        if ($mimeTypes === null) {
            $mimeTypes = MimeTypes::getDefault();
        }

        $fromExt = $mimeTypes->getMimeTypes($this->fileInfo->getExtension());
        try {
            return $mimeTypes->guessMimeType($this->fileInfo->getPathname());
        } catch (LogicException | InvalidArgumentException) {
            return empty($fromExt) ? 'application/octet-stream' : $fromExt[array_key_first($fromExt)];
        }
    }

    public function visibility(): Visibility
    {
        $converter = new PortableVisibilityConverter();
        if ($this->fileInfo->isDir()) {
            return $converter->inverseForDirectory($this->fileInfo->getPerms());
        }

        return $converter->inverseForFile($this->fileInfo->getPerms());
    }
}
