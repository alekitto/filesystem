<?php

declare(strict_types=1);

namespace Kcs\Filesystem\GCS;

use DateTimeImmutable;
use DateTimeInterface;
use Google\Cloud\Storage\Acl;
use Google\Cloud\Storage\StorageObject;
use Kcs\Filesystem\FileStatInterface;
use Kcs\Filesystem\Visibility;
use Symfony\Component\Mime\MimeTypes;

use function array_key_first;

class GCSFileStat implements FileStatInterface
{
    private DateTimeImmutable $lastModified;
    private int $fileSize;
    private string|null $mimeType = null;

    public function __construct(
        private readonly StorageObject $object,
        private readonly string $key,
        private readonly string $relativeKey,
    ) {
        $info = $this->object->info();
        $this->lastModified = new DateTimeImmutable($info['updated'] ?? '@0');
        $this->fileSize = $info['size'] ?? -1;
        $this->mimeType = $info['contentType'] ?? 'application/octet-stream';
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

        return empty($fromExt) ? 'application/octet-stream' : $fromExt[array_key_first($fromExt)];
    }

    public function visibility(): Visibility
    {
        $acl = $this->object->acl()->get();
        foreach ($acl as $entry) {
            if (! isset($entry['entity'], $entry['role'])) {
                continue;
            }

            if (
                ($entry['entity'] === 'allUsers' || $entry['entity'] === 'allAuthenticatedUsers')
                && $entry['role'] === Acl::ROLE_READER
            ) {
                return Visibility::Public;
            }
        }

        return Visibility::Private;
    }
}
