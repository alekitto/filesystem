<?php

declare(strict_types=1);

namespace Tests\GCS;

use Doctrine\Common\Collections\Collection;
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use GuzzleHttp\Psr7\Utils;
use Kcs\Filesystem\Exception\OperationException;
use Kcs\Filesystem\GCS\GCSFilesystem;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

use function is_resource;
use function stream_get_contents;

class GCSFilesystemTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var StorageClient|ObjectProphecy
     */
    private $client;

    /**
     * @var Bucket|ObjectProphecy
     */
    private $bucket;

    private GCSFilesystem $fs;

    protected function setUp(): void
    {
        $this->client = $this->prophesize(StorageClient::class);
        $this->bucket = $this->prophesize(Bucket::class);
        $this->client->bucket('bucket')->willReturn($this->bucket->reveal());

        $this->fs = new GCSFilesystem('bucket', '/', $this->client->reveal());
    }

    public function testExists(): void
    {
        $existent = $this->prophesize(StorageObject::class);
        $existent->exists()->willReturn(true);
        $this->bucket->object('existent.txt')->willReturn($existent->reveal());

        $missing = $this->prophesize(StorageObject::class);
        $missing->exists()->willReturn(false);
        $this->bucket->object('missing.txt')->willReturn($missing->reveal());

        self::assertTrue($this->fs->exists('existent.txt'));
        self::assertFalse($this->fs->exists('missing.txt'));
    }

    public function testReadShouldThrowIfRequestingToReadADirectory(): void
    {
        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Cannot read a directory');

        $this->fs->read('path/');
    }

    public function testReadShouldThrowIfFileDoesNotExist(): void
    {
        $object = $this->prophesize(StorageObject::class);
        $object->downloadAsStream()->willThrow(new NotFoundException('Not found'));
        $this->bucket->object('missing.txt')->willReturn($object->reveal());

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('File does not exist');

        $this->fs->read('missing.txt');
    }

    public function testReadShouldReturnAReadableStream(): void
    {
        $object = $this->prophesize(StorageObject::class);
        $object->downloadAsStream()->willReturn(Utils::streamFor('TEST'));
        $this->bucket->object('file.txt')->willReturn($object->reveal());

        $stream = $this->fs->read('file.txt');
        self::assertSame('TEST', $stream->read(10));
    }

    public function testListShouldNotListDeeply(): void
    {
        $this->bucket
            ->objects(['prefix' => 'dir', 'delimiter' => '/'])
            ->willReturn(new \ArrayIterator([]))
            ->shouldBeCalled();

        $collection = $this->fs->list('dir');
        self::assertInstanceOf(Collection::class, $collection);
    }

    public function testListShouldListDeeply(): void
    {
        $this->bucket
            ->objects(['prefix' => 'dir'])
            ->willReturn(new \ArrayIterator([]))
            ->shouldBeCalled();

        $collection = $this->fs->list('dir', true);
        self::assertInstanceOf(Collection::class, $collection);
    }

    public function testStatReturnsFileDetails(): void
    {
        $object = $this->prophesize(StorageObject::class);
        $object->info()->willReturn([
            'updated' => '2020-01-01T00:00:00Z',
            'size' => 10,
            'contentType' => 'text/plain',
        ]);
        $this->bucket->object('file.txt')->willReturn($object->reveal());

        $stat = $this->fs->stat('file.txt');
        self::assertSame('file.txt', $stat->path());
        self::assertSame(10, $stat->fileSize());
        self::assertSame('text/plain', $stat->mimeType());
    }

    public function testWriteShouldUploadWithOptions(): void
    {
        $this->bucket
            ->upload(
                Argument::type('resource'),
                Argument::that(function (array $options): bool {
                    if (($options['name'] ?? null) !== 'path.txt') {
                        return false;
                    }

                    if (($options['predefinedAcl'] ?? null) !== 'publicRead') {
                        return false;
                    }

                    $metadata = $options['metadata'] ?? [];

                    return ($metadata['contentType'] ?? null) === 'text/plain'
                        && ($metadata['foo'] ?? null) === 'bar';
                })
            )
            ->will(function (array $args): void {
                $resource = $args[0];
                self::assertTrue(is_resource($resource));
                self::assertSame('HELLO', stream_get_contents($resource));
            })
            ->shouldBeCalled();

        $this->fs->write('path.txt', 'HELLO', [
            'content-type' => 'text/plain',
            'gcs' => [
                'predefined-acl' => 'publicRead',
                'metadata' => ['foo' => 'bar'],
            ],
        ]);
    }

    public function testDeleteShouldIgnoreMissingObjects(): void
    {
        $object = $this->prophesize(StorageObject::class);
        $object->delete()->willThrow(new NotFoundException('missing'))->shouldBeCalled();
        $this->bucket->object('missing.txt')->willReturn($object->reveal());

        $this->fs->delete('missing.txt');
    }

    public function testDeleteDirectoryShouldRemoveAllObjects(): void
    {
        $object1 = $this->prophesize(StorageObject::class);
        $object1->delete()->shouldBeCalled();
        $object2 = $this->prophesize(StorageObject::class);
        $object2->delete()->shouldBeCalled();

        $this->bucket
            ->objects(['prefix' => 'dir'])
            ->willReturn(new \ArrayIterator([$object1->reveal(), $object2->reveal()]))
            ->shouldBeCalled();

        $this->fs->deleteDirectory('dir');
    }

    public function testCreateDirectoryShouldCreatePlaceholderObject(): void
    {
        $this->bucket
            ->upload(
                Argument::type('resource'),
                Argument::that(static fn (array $options): bool => ($options['name'] ?? null) === 'dir')
            )
            ->will(function (array $args): void {
                $resource = $args[0];
                self::assertTrue(is_resource($resource));
                self::assertSame('', stream_get_contents($resource));
            })
            ->shouldBeCalled();

        $this->fs->createDirectory('dir');
    }

    public function testMoveShouldCopyAndDelete(): void
    {
        $source = $this->prophesize(StorageObject::class);
        $source->exists()->willReturn(true);
        $source
            ->copy('bucket', ['name' => 'dest.txt'])
            ->shouldBeCalled();
        $source->delete()->shouldBeCalled();

        $destination = $this->prophesize(StorageObject::class);
        $destination->exists()->willReturn(false);

        $this->bucket->object('src.txt')->willReturn($source->reveal());
        $this->bucket->object('dest.txt')->willReturn($destination->reveal());

        $this->fs->move('src.txt', 'dest.txt');
    }

    public function testCopyShouldThrowIfSourceDoesNotExist(): void
    {
        $source = $this->prophesize(StorageObject::class);
        $source->exists()->willReturn(false);
        $this->bucket->object('src.txt')->willReturn($source->reveal());

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Cannot copy file: source does not exist');

        $this->fs->copy('src.txt', 'dest.txt');
    }

    public function testCopyShouldThrowIfDestinationExistsAndNoOverwrite(): void
    {
        $source = $this->prophesize(StorageObject::class);
        $source->exists()->willReturn(true);
        $this->bucket->object('src.txt')->willReturn($source->reveal());

        $destination = $this->prophesize(StorageObject::class);
        $destination->exists()->willReturn(true);
        $this->bucket->object('dest.txt')->willReturn($destination->reveal());

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Cannot copy file: destination already exist and overwrite flag is not set');

        $this->fs->copy('src.txt', 'dest.txt');
    }
}
