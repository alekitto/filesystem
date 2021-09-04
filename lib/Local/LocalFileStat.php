<?php

declare(strict_types=1);

namespace Kcs\Filesystem\Local;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Kcs\Filesystem\FileStatInterface;
use LogicException;
use SplFileInfo;
use Symfony\Component\Mime\MimeTypes;

use function array_key_first;

class LocalFileStat implements FileStatInterface
{
    private SplFileInfo $fileInfo;
    private DateTimeImmutable $lastModified;
    private int $fileSize;

    public function __construct(SplFileInfo $fileInfo)
    {
        $this->fileInfo = $fileInfo;
        $this->lastModified = new DateTimeImmutable('@' . $fileInfo->getMTime());
        $this->fileSize = $fileInfo->isDir() ? -1 : $fileInfo->getSize();
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
        } catch (LogicException | InvalidArgumentException $e) {
            return empty($fromExt) ? 'application/octet-stream' : $fromExt[array_key_first($fromExt)];
        }
    }
}
