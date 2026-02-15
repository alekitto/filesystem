<?php

declare(strict_types=1);

namespace Kcs\Filesystem\GCS;

use Doctrine\Common\Collections\Collection;
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Storage\StorageClient;
use Kcs\Filesystem\Exception\OperationException;
use Kcs\Filesystem\FileStatInterface;
use Kcs\Filesystem\Filesystem;
use Kcs\Filesystem\PathNormalizer;
use Kcs\Stream\BufferStream;
use Kcs\Stream\PumpStream;
use Kcs\Stream\ReadableStream;
use Throwable;

use function array_merge;
use function assert;
use function fopen;
use function fwrite;
use function is_string;
use function ltrim;
use function preg_replace;
use function rewind;
use function rtrim;
use function str_ends_with;
use function trim;

class GCSFilesystem implements Filesystem
{
    public function __construct(
        private readonly string $bucket,
        private readonly string $prefix = '/',
        private readonly StorageClient $client = new StorageClient(),
    ) {
    }

    public function exists(string $location): bool
    {
        return $this->client
            ->bucket($this->bucket)
            ->object($this->prefix($location))
            ->exists();
    }

    public function read(string $location): ReadableStream
    {
        if (str_ends_with($location, '/')) {
            throw new OperationException('Cannot read a directory');
        }

        try {
            $stream = $this->client
                ->bucket($this->bucket)
                ->object($this->prefix($location))
                ->downloadAsStream();
        } catch (NotFoundException $e) {
            throw new OperationException('File does not exist', $e);
        } catch (Throwable $e) {
            throw new OperationException('Error while reading file', $e);
        }

        return new PumpStream(static function () use ($stream) {
            if ($stream->eof()) {
                return false;
            }

            return $stream->read(4096);
        });
    }

    /** @return Collection<FileStatInterface> */
    public function list(string $location, bool $deep = false): Collection
    {
        $options = ['prefix' => $this->prefix($location)];
        if ($deep === false) {
            $options['delimiter'] = '/';
        }

        $iterator = $this->client
            ->bucket($this->bucket)
            ->objects($options);

        return new GCSCollection($iterator, $this->prefix);
    }

    public function stat(string $location): FileStatInterface
    {
        $path = $this->prefix($location);

        try {
            return new GCSFileStat(
                $this->client
                    ->bucket($this->bucket)
                    ->object($path),
                $path,
                PathNormalizer::normalizePath($location),
            );
        } catch (NotFoundException $e) {
            throw new OperationException('File does not exist', $e);
        } catch (Throwable $e) {
            throw new OperationException('Error while requesting file details', $e);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @phpstan-param array{content-type?:string, gcs?: array{content-type?: string, predefined-acl?: string[], predefinedAcl?: string[], metadata?: array<string, string>}} $config
     */
    public function write(string $location, ReadableStream|string $contents, array $config = []): void
    {
        if (is_string($contents)) {
            $contentStream = new BufferStream();
            $contentStream->write($contents);

            $contents = $contentStream;
        }

        $prefixed = $this->prefix($location);
        $options = ['name' => $prefixed];

        $contentType = $config['content-type'] ?? $config['gcs']['content-type'] ?? null;
        if ($contentType !== null) {
            $options['metadata']['contentType'] = $contentType;
        }

        if (isset($config['gcs']['predefined-acl'])) {
            $options['predefinedAcl'] = $config['gcs']['predefined-acl'];
        } elseif (isset($config['gcs']['predefinedAcl'])) {
            $options['predefinedAcl'] = $config['gcs']['predefinedAcl'];
        }

        if (isset($config['gcs']['metadata'])) {
            $options['metadata'] = array_merge($options['metadata'] ?? [], $config['gcs']['metadata']);
        }

        $resource = fopen('php://temp', 'wb+');
        if ($resource === false) {
            throw new OperationException('Unable to open temporary stream for upload');
        }

        while (! $contents->eof()) {
            $chunk = $contents->read(8192);
            if ($chunk === '') {
                break;
            }

            fwrite($resource, $chunk);
        }

        rewind($resource);

        try {
            $this->client
                ->bucket($this->bucket)
                ->upload($resource, $options);
        } catch (Throwable $e) {
            throw new OperationException('Failed to write file', $e);
        }
    }

    public function delete(string $location): void
    {
        $path = $this->prefix($location);
        try {
            $this->client
                ->bucket($this->bucket)
                ->object($path)
                ->delete();
        } catch (NotFoundException) {
            return;
        } catch (Throwable $e) {
            throw new OperationException('Error while deleting file', $e);
        }
    }

    public function deleteDirectory(string $location): void
    {
        $prefix = $this->prefix($location);
        $objects = $this->client
            ->bucket($this->bucket)
            ->objects(['prefix' => $prefix]);

        foreach ($objects as $object) {
            $object->delete();
        }
    }

    /**
     * {@inheritDoc}
     *
     * @phpstan-param array{gcs?: array{predefined-acl?: string[], predefinedAcl?: string[], metadata?: array<string, string>}} $config
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $this->write(rtrim($this->prefix($location), '/') . '/', '', $config);
    }

    /** @inheritDoc */
    public function move(string $source, string $destination, array $config = []): void
    {
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    /**
     * {@inheritDoc}
     *
     * @phpstan-param array{overwrite?: bool, gcs?: array{predefined-acl?: string[], predefinedAcl?: string[]}} $config
     */
    public function copy(string $source, string $destination, array $config = []): void
    {
        $sourcePath = $this->prefix($source);
        $destinationPath = $this->prefix($destination);

        $sourceObject = $this->client
            ->bucket($this->bucket)
            ->object($sourcePath);

        if (! $sourceObject->exists()) {
            throw new OperationException('Cannot copy file: source does not exist');
        }

        $overwrite = $config['overwrite'] ?? false;
        if (! $overwrite && $this->exists($destination)) {
            throw new OperationException('Cannot copy file: destination already exist and overwrite flag is not set');
        }

        $options = ['name' => $destinationPath];
        if (isset($config['gcs']['predefined-acl'])) {
            $options['predefinedAcl'] = $config['gcs']['predefined-acl'];
        } elseif (isset($config['gcs']['predefinedAcl'])) {
            $options['predefinedAcl'] = $config['gcs']['predefinedAcl'];
        }

        $sourceObject->copy($this->bucket, $options);
    }

    private function prefix(string $location): string
    {
        $location = preg_replace('#[/]+#', '/', $this->prefix . '/' . PathNormalizer::normalizePath($location));
        assert(is_string($location));

        if ($location !== '/') {
            return trim($location, '/');
        }

        return ltrim($location, '/');
    }
}
