<?php

declare(strict_types=1);

namespace Kcs\Filesystem\AsyncS3;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\Core\Exception\Http\ServerException;
use AsyncAws\S3\Exception\InvalidObjectStateException;
use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\S3\S3Client;
use AsyncAws\S3\ValueObject\AwsObject;
use AsyncAws\S3\ValueObject\CommonPrefix;
use AsyncAws\S3\ValueObject\CompletedMultipartUpload;
use AsyncAws\S3\ValueObject\CompletedPart;
use AsyncAws\S3\ValueObject\ObjectIdentifier;
use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Kcs\Filesystem\Exception\OperationException;
use Kcs\Filesystem\FileStatInterface;
use Kcs\Filesystem\Filesystem;
use Kcs\Filesystem\IterableIterator;
use Kcs\Filesystem\PathNormalizer;
use Kcs\Stream\BufferStream;
use Kcs\Stream\PumpStream;
use Kcs\Stream\ReadableStream;
use Throwable;

use function assert;
use function base64_encode;
use function hash;
use function is_string;
use function preg_replace;
use function rawurlencode;
use function rtrim;
use function str_ends_with;
use function strlen;

use const PHP_INT_MAX;

class AsyncS3Filesystem implements Filesystem
{
    private string $bucket;
    private string $prefix;
    private S3Client $client;

    public function __construct(string $bucket, string $prefix = '/', ?S3Client $client = null)
    {
        $this->bucket = $bucket;
        $this->prefix = $prefix;
        $this->client = $client ?? new S3Client();
    }

    public function exists(string $location): bool
    {
        return $this->client->objectExists([
            'Bucket' => $this->bucket,
            'Key' => $this->prefix($location),
        ])->isSuccess();
    }

    public function read(string $location): ReadableStream
    {
        if (str_ends_with($location, '/')) {
            throw new OperationException('Cannot read a directory');
        }

        $result = $this->client->GetObject([
            'Bucket' => $this->bucket,
            'Key' => $this->prefix($location),
        ]);

        try {
            $chunks = new IterableIterator($result->getBody()->getChunks());
        } catch (NoSuchKeyException $e) {
            throw new OperationException('File does not exists', $e);
        } catch (InvalidObjectStateException $e) {
            throw new OperationException('File cannot be read', $e);
        } catch (ClientException | ServerException $e) {
            throw new OperationException('Error while reading file', $e);
        }

        return new PumpStream(static function () use ($chunks) {
            if (! $chunks->valid()) {
                return false;
            }

            $chunk = $chunks->current();
            $chunks->next();

            return $chunk;
        });
    }

    /**
     * @return Collection<FileStatInterface>
     */
    public function list(string $location, bool $deep = false): Collection
    {
        $options = ['Bucket' => $this->bucket, 'Prefix' => $this->prefix($location)];
        if ($deep === false) {
            $options['Delimiter'] = '/';
        }

        $iterator = $this->client->listObjectsV2($options);

        return new class ($iterator) extends AbstractLazyCollection {
            /** @var iterable<AwsObject|CommonPrefix> */
            private iterable $iterator;

            /**
             * @param iterable<AwsObject|CommonPrefix> $iterator
             */
            public function __construct(iterable $iterator)
            {
                $this->iterator = $iterator;
            }

            protected function doInitialize(): void
            {
                $this->collection = new ArrayCollection();

                foreach ($this->iterator as $item) {
                    $key = $item instanceof AwsObject ? $item->getKey() : $item->getPrefix();
                    assert($key !== null);

                    $this->collection[] = new S3FileStat($item, $key);
                }
            }
        };
    }

    public function stat(string $location): FileStatInterface
    {
        $path = $this->prefix($location);
        try {
            return new S3FileStat($this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]), $path);
        } catch (NoSuchKeyException $e) {
            throw new OperationException('File does not exists', $e);
        } catch (ClientException | ServerException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                throw new OperationException('File does not exists', $e);
            }

            throw new OperationException('Error while requesting file details', $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function write(string $location, $contents, array $config = []): void
    {
        if (is_string($contents)) {
            $contentStream = new BufferStream();
            $contentStream->write($contents);

            $contents = $contentStream;
        }

        $options = [];
        $prefixed = $this->prefix($location);
        $streamLength = $contents->length();
        if ($streamLength !== null) {
            $options['ContentLength'] = $streamLength;
        }

        if (isset($config['s3'])) {
            $s3Config = $config['s3'];
            if (isset($s3Config['acl'])) {
                $options['ACL'] = $s3Config['acl'];
            }

            if (isset($s3Config['cache-control'])) {
                $options['CacheControl'] = $s3Config['cache-control'];
            }

            if (isset($s3Config['content-type'])) {
                $options['ContentType'] = $s3Config['content-type'];
            }

            if (isset($s3Config['metadata'])) {
                $options['Metadata'] = $s3Config['metadata'];
            }
        }

        if ($streamLength !== null && $streamLength <= 5 * 1024 * 1024) {
            $body = $contents->read(PHP_INT_MAX);
            $hash = base64_encode(hash('md5', $body, true));
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $prefixed,
                'Body' => $body,
                'ContentLength' => strlen($body),
                'ContentMD5' => $hash,
            ] + $options);
        } else {
            $uploadId = $this->client->createMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $prefixed,
            ] + $options)->getUploadId();
            assert(is_string($uploadId));

            try {
                $partNumber = 0;
                $parts = [];
                while (! $contents->eof()) {
                    $partNumber++;
                    $body = $contents->read(5 * 1024 * 1024);

                    $response = $this->client->uploadPart([
                        'Bucket' => $this->bucket,
                        'Key' => $prefixed,
                        'UploadId' => $uploadId,
                        'PartNumber' => $partNumber,
                        'ContentLength' => strlen($body),
                        'ContentMD5' => base64_encode(hash('md5', $body, true)),
                        'Body' => $body,
                    ]);

                    $parts[] = new CompletedPart([
                        'ETag' => $response->getETag(),
                        'PartNumber' => $partNumber,
                    ]);
                }
            } catch (Throwable $e) {
                $this->client->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $prefixed, 'UploadId' => $uploadId]);

                throw new OperationException('Failed to upload file', $e);
            }

            $this->client->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $prefixed,
                'UploadId' => $uploadId,
                'MultipartUpload' => new CompletedMultipartUpload(['Parts' => $parts]),
            ]);
        }
    }

    public function delete(string $location): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $this->prefix($location),
        ]);
    }

    public function deleteDirectory(string $location): void
    {
        $objects = [];
        $result = $this->client->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => $this->prefix($location)]);

        foreach ($result->getContents() as $item) {
            $key = $item->getKey();
            if ($key === null) {
                continue;
            }

            $objects[] = new ObjectIdentifier(['Key' => $key]);
        }

        if (empty($objects)) {
            return;
        }

        $this->client->deleteObjects([
            'Bucket' => $this->bucket,
            'Delete' => ['Objects' => $objects],
        ]);
    }

    /**
     * @inheritDoc
     */
    public function createDirectory(string $location, array $config = []): void
    {
        $this->write(rtrim($this->prefix($location), '/') . '/', '', $config);
    }

    /**
     * @inheritDoc
     */
    public function move(string $source, string $destination, array $config = []): void
    {
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    /**
     * @inheritDoc
     */
    public function copy(string $source, string $destination, array $config = []): void
    {
        if (! $this->exists($source)) {
            throw new OperationException('Cannot move file: source does not exist');
        }

        $sourcePath = $this->bucket . '/' . $this->prefix($source);
        $overwrite = $config['overwrite'] ?? false;
        if (! $overwrite && $this->exists($destination)) {
            throw new OperationException('Cannot move file: destination already exist and overwrite flag is not set');
        }

        $destinationPath = $this->prefix($destination);
        $options = [
            'Bucket' => $this->bucket,
            'Key' => $destinationPath,
            'CopySource' => rawurlencode($sourcePath),
        ];

        if (isset($config['s3']['acl'])) {
            $options['ACL'] = $config['s3']['acl'];
        }

        $this->client->copyObject($options);
    }

    private function prefix(string $location): string
    {
        $location = preg_replace('#[/]+#', '/', $this->prefix . '/' . PathNormalizer::normalizePath($location));
        assert(is_string($location));

        return $location !== '/' ? rtrim($location, '/') : $location;
    }
}
