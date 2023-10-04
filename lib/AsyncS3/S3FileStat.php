<?php

declare(strict_types=1);

namespace Kcs\Filesystem\AsyncS3;

use AsyncAws\S3\Result\HeadObjectOutput;
use AsyncAws\S3\ValueObject\AwsObject;
use AsyncAws\S3\ValueObject\CommonPrefix;
use DateTimeImmutable;
use DateTimeInterface;
use Kcs\Filesystem\FileStatInterface;
use Symfony\Component\Mime\MimeTypes;

use function array_key_first;

class S3FileStat implements FileStatInterface
{
    private DateTimeImmutable $lastModified;
    private int $fileSize;
    private string|null $mimeType = null;

    public function __construct(
        private readonly AwsObject|CommonPrefix|HeadObjectOutput $object,
        private readonly string $key,
        private readonly string $relativeKey,
    ) {
        if ($object instanceof AwsObject) {
            $this->lastModified = $object->getLastModified() ?? new DateTimeImmutable('@0');
            $this->fileSize = (int) $object->getSize();
        } elseif ($object instanceof HeadObjectOutput) {
            $this->lastModified = $object->getLastModified() ?? new DateTimeImmutable('@0');
            $this->fileSize = (int) $object->getContentLength();
            $this->mimeType = $object->getContentType();
        } else {
            $this->lastModified = new DateTimeImmutable('@0');
            $this->fileSize = -1;
            $this->mimeType = 'application/x-directory';
        }
    }

    public function path(): string
    {
        return $this->relativeKey;
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
        if ($this->mimeType !== null) {
            return $this->mimeType;
        }

        static $mimeTypes = null;
        if ($mimeTypes === null) {
            $mimeTypes = MimeTypes::getDefault();
        }

        $fromExt = $mimeTypes->getMimeTypes($this->key);

        if ($this->object instanceof HeadObjectOutput) {
            $contentType = $this->object->getContentType();
            if ($contentType !== null) {
                return $contentType;
            }
        }

        return empty($fromExt) ? 'application/octet-stream' : $fromExt[array_key_first($fromExt)];
    }
}
