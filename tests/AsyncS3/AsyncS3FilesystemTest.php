<?php

declare(strict_types=1);

namespace Tests\AsyncS3;

use AsyncAws\Core\AwsError\ChainAwsErrorFactory;
use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\Core\Response;
use AsyncAws\S3\Exception\InvalidObjectStateException;
use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\S3\Result\CreateMultipartUploadOutput;
use AsyncAws\S3\Result\GetObjectOutput;
use AsyncAws\S3\Result\HeadObjectOutput;
use AsyncAws\S3\Result\ListObjectsV2Output;
use AsyncAws\S3\Result\ObjectExistsWaiter;
use AsyncAws\S3\Result\UploadPartOutput;
use AsyncAws\S3\S3Client;
use AsyncAws\S3\ValueObject\CompletedMultipartUpload;
use AsyncAws\S3\ValueObject\CompletedPart;
use Kcs\Filesystem\AsyncS3\AsyncS3Filesystem;
use Kcs\Filesystem\Exception\OperationException;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AsyncS3FilesystemTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var S3Client|ObjectProphecy
     */
    private $client;
    private AsyncS3Filesystem $fs;

    protected function setUp(): void
    {
        $this->client = $this->prophesize(S3Client::class);
        $this->fs = new AsyncS3Filesystem('bucket', $this->client->reveal());
    }

    public function testExists(): void
    {
        $response = $this->prophesize(ResponseInterface::class);
        $response->getStatusCode()->willReturn(200);
        $success = new ObjectExistsWaiter(new Response($response->reveal(), $this->prophesize(HttpClientInterface::class)->reveal(), new NullLogger()), $this->client->reveal(), null);

        $this->client->objectExists([
            'Bucket' => 'bucket',
            'Key' => 'existent.txt'
        ])->willReturn($success);

        $response = $this->prophesize(ResponseInterface::class);
        $response->getStatusCode()->willReturn(404);
        $response->getContent(false)->willReturn('{}');
        $response->getHeaders(false)->willReturn([]);
        $response->getInfo('http_code')->willReturn(404);
        $response->getInfo('url')->willReturn('http://localhost');

        $failure = new ObjectExistsWaiter(new Response($response->reveal(), $this->prophesize(HttpClientInterface::class)->reveal(), new NullLogger()), $this->client->reveal(), null);

        $this->client->objectExists([
            'Bucket' => 'bucket',
            'Key' => 'non-existent.txt'
        ])->willReturn($failure);

        self::assertTrue($this->fs->exists('existent.txt'));
        self::assertFalse($this->fs->exists('non-existent.txt'));
    }

    public function testReadShouldThrowIfRequestingToReadADirectory(): void
    {
        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Cannot read a directory');

        $this->fs->read('location/');
    }

    public function testReadShouldThrowIfFileDoesNotExist(): void
    {
        $content = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Error>
    <Code>NoSuchKey</Code>
    <Message>The specified key does not exist.</Message>
    <Key>this-file</Key>
    <RequestId>E35DA9F89E2F4155</RequestId>
    <HostId>fQa+jP2WL4wWRe+OFbw9H9HNqoailc7Zv+nRsjEqXjrsOuIy1ubQ1rOXA=</HostId>
</Error>
XML;

        $response = MockResponse::fromRequest('GET', 'http://localhost', [], new MockResponse($content, ['http_code' => 404, 'headers' => ['x-amzn-errortype' => 'NoSuchKey']]));
        $awsResponse = new Response(
            $response,
            $this->prophesize(HttpClientInterface::class)->reveal(),
            new NullLogger(),
            new ChainAwsErrorFactory(),
            true,
            [
                'NoSuchKey' => NoSuchKeyException::class,
            ]
        );

        $this->client->getObject([
            'Bucket' => 'bucket',
            'Key' => 'this-file'
        ])->willReturn(new GetObjectOutput($awsResponse));

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('File does not exists: HTTP 404 returned for "http://localhost"');
        $this->fs->read('this-file');
    }

    public function testReadShouldThrowIfFileCannotBeReadDueToItsStatus(): void
    {
        $content = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Error>
    <Code>InvalidObjectState</Code>
    <Message>Object is archived.</Message>
    <Key>this-file</Key>
    <RequestId>E35DA9F89E2F4155</RequestId>
    <HostId>fQa+jP2WL4wWRe+OFbw9H9HNqoailc7Zv+nRsjEqXjrsOuIy1ubQ1rOXA=</HostId>
</Error>
XML;

        $response = MockResponse::fromRequest('GET', 'http://localhost', [], new MockResponse($content, ['http_code' => 400, 'headers' => ['x-amzn-errortype' => 'InvalidObjectState']]));
        $awsResponse = new Response(
            $response,
            $this->prophesize(HttpClientInterface::class)->reveal(),
            new NullLogger(),
            new ChainAwsErrorFactory(),
            true,
            [
                'InvalidObjectState' => InvalidObjectStateException::class,
            ]
        );

        $this->client->getObject([
            'Bucket' => 'bucket',
            'Key' => 'this-file'
        ])->willReturn(new GetObjectOutput($awsResponse));

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('File cannot be read');
        $this->fs->read('this-file');
    }

    public function testReadShouldThrowOnException(): void
    {
        $response = MockResponse::fromRequest('GET', 'http://localhost', [], new MockResponse('{}', ['http_code' => 500]));
        $awsResponse = new Response(
            $response,
            $this->prophesize(HttpClientInterface::class)->reveal(),
            new NullLogger(),
        );

        $this->client->getObject([
            'Bucket' => 'bucket',
            'Key' => 'this-file'
        ])->willReturn(new GetObjectOutput($awsResponse));

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Error while reading file');
        $this->fs->read('this-file');
    }

    public function testListShouldNotListDeeply(): void
    {
        $response = MockResponse::fromRequest('GET', 'http://localhost', [], new MockResponse('{}', ['http_code' => 200]));
        $awsResponse = new Response(
            $response,
            $this->prophesize(HttpClientInterface::class)->reveal(),
            new NullLogger(),
        );

        $output = new ListObjectsV2Output($awsResponse);

        $this->client->listObjectsV2([
            'Bucket' => 'bucket',
            'Prefix' => '/',
            'Delimiter' => '/'
        ])
            ->shouldBeCalled()
            ->willReturn($output);

        $this->fs->list('/', false);
    }

    public function testListShouldListDeeply(): void
    {
        $response = MockResponse::fromRequest('GET', 'http://localhost', [], new MockResponse('{}', ['http_code' => 200]));
        $awsResponse = new Response(
            $response,
            $this->prophesize(HttpClientInterface::class)->reveal(),
            new NullLogger(),
        );

        $output = new ListObjectsV2Output($awsResponse);

        $this->client->listObjectsV2([
            'Bucket' => 'bucket',
            'Prefix' => '/',
        ])
            ->shouldBeCalled()
            ->willReturn($output);

        $this->fs->list('/', true);
    }

    public function testStatShouldThrowIfFileDoesNotExist(): void
    {
        $content = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Error>
    <Code>NoSuchKey</Code>
    <Message>The specified key does not exist.</Message>
    <Key>this-file</Key>
    <RequestId>E35DA9F89E2F4155</RequestId>
    <HostId>fQa+jP2WL4wWRe+OFbw9H9HNqoailc7Zv+nRsjEqXjrsOuIy1ubQ1rOXA=</HostId>
</Error>
XML;

        $response = MockResponse::fromRequest('GET', 'http://localhost', [], new MockResponse($content, ['http_code' => 404, 'headers' => ['x-amzn-errortype' => 'NoSuchKey']]));
        $awsResponse = new Response(
            $response,
            $this->prophesize(HttpClientInterface::class)->reveal(),
            new NullLogger(),
            new ChainAwsErrorFactory(),
            true,
            [
                'NoSuchKey' => NoSuchKeyException::class,
            ]
        );

        $this->client->headObject([
            'Bucket' => 'bucket',
            'Key' => 'this-file'
        ])->willReturn(new HeadObjectOutput($awsResponse));

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('File does not exists: HTTP 404 returned for "http://localhost"');
        $this->fs->stat('this-file');
    }

    public function testStatShouldThrowOnException(): void
    {
        $response = MockResponse::fromRequest('GET', 'http://localhost', [], new MockResponse('{}', ['http_code' => 500]));
        $awsResponse = new Response(
            $response,
            $this->prophesize(HttpClientInterface::class)->reveal(),
            new NullLogger()
        );

        $this->client->headObject([
            'Bucket' => 'bucket',
            'Key' => 'this-file'
        ])->willReturn(new HeadObjectOutput($awsResponse));

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Error while requesting file details: HTTP 500 returned for "http://localhost"');
        $this->fs->stat('this-file');
    }

    public function testWriteShouldUsePutObjectForSmallUploads(): void
    {
        $contents = str_repeat('a', 5 * 1024 * 1024);
        $this->client->putObject([
            'Bucket' => 'bucket',
            'Key' => 'test.txt',
            'Body' => $contents,
            'ContentLength' => 5 * 1024 * 1024,
            'ContentMD5' => base64_encode(hash('md5', $contents, true)),
            'ACL' => 'public-read',
            'CacheControl' => 'public, max-age=604800, immutable',
            'ContentType' => 'text/plain',
            'Metadata' => [
                'my-info' => 'test',
            ],
        ])
            ->shouldBeCalled();

        $this->fs->write('test.txt', $contents, [
            's3' => [
                'acl' => 'public-read',
                'cache-control' => 'public, max-age=604800, immutable',
                'content-type' => 'text/plain',
                'metadata' => [
                    'my-info' => 'test',
                ],
            ],
        ]);
    }

    public function testWriteShouldUseMultipartUploads(): void
    {
        $contents = str_repeat('a', 5 * 1024 * 1024 + 1);
        $this->client->createMultipartUpload([
            'Bucket' => 'bucket',
            'Key' => 'test.txt',
            'ContentLength' => 5 * 1024 * 1024 + 1,
            'ACL' => 'public-read',
            'CacheControl' => 'public, max-age=604800, immutable',
            'ContentType' => 'text/plain',
            'Metadata' => [
                'my-info' => 'test',
            ],
        ])
            ->shouldBeCalled()
            ->willReturn($upload = $this->prophesize(CreateMultipartUploadOutput::class));
        $upload->getUploadId()->willReturn('upload-id');

        $part = str_repeat('a', 5 * 1024 * 1024);
        $this->client->uploadPart([
            'Bucket' => 'bucket',
            'Key' => 'test.txt',
            'UploadId' => 'upload-id',
            'PartNumber' => 1,
            'ContentLength' => 5 * 1024 * 1024,
            'ContentMD5' => base64_encode(hash('md5', $part, true)),
            'Body' => $part,
        ])
            ->shouldBeCalled()
            ->willReturn($partResponse = $this->prophesize(UploadPartOutput::class));
        $partResponse->getEtag()->willReturn('etag_1');

        $this->client->uploadPart([
            'Bucket' => 'bucket',
            'Key' => 'test.txt',
            'UploadId' => 'upload-id',
            'PartNumber' => 2,
            'ContentLength' => 1,
            'ContentMD5' => base64_encode(hash('md5', 'a', true)),
            'Body' => 'a',
        ])
            ->shouldBeCalled()
            ->willReturn($partResponse = $this->prophesize(UploadPartOutput::class));
        $partResponse->getEtag()->willReturn('etag_2');

        $this->client->completeMultipartUpload([
            'Bucket' => 'bucket',
            'Key' => 'test.txt',
            'UploadId' => 'upload-id',
            'MultipartUpload' => new CompletedMultipartUpload(['Parts' => [
                new CompletedPart([
                    'ETag' => 'etag_1',
                    'PartNumber' => 1,
                ]),
                new CompletedPart([
                    'ETag' => 'etag_2',
                    'PartNumber' => 2,
                ])
            ]]),
        ])
            ->shouldBeCalled();

        $this->fs->write('test.txt', $contents, [
            's3' => [
                'acl' => 'public-read',
                'cache-control' => 'public, max-age=604800, immutable',
                'content-type' => 'text/plain',
                'metadata' => [
                    'my-info' => 'test',
                ],
            ],
        ]);
    }

    public function testWriteShouldAbortMultipartUploadOnException(): void
    {
        $contents = str_repeat('a', 5 * 1024 * 1024 + 1);
        $this->client->createMultipartUpload([
            'Bucket' => 'bucket',
            'Key' => 'test.txt',
            'ContentLength' => 5 * 1024 * 1024 + 1,
            'ACL' => 'public-read',
            'CacheControl' => 'public, max-age=604800, immutable',
            'ContentType' => 'text/plain',
            'Metadata' => [
                'my-info' => 'test',
            ],
        ])
            ->shouldBeCalled()
            ->willReturn($upload = $this->prophesize(CreateMultipartUploadOutput::class));
        $upload->getUploadId()->willReturn('upload-id');

        $part = str_repeat('a', 5 * 1024 * 1024);
        $this->client->uploadPart([
            'Bucket' => 'bucket',
            'Key' => 'test.txt',
            'UploadId' => 'upload-id',
            'PartNumber' => 1,
            'ContentLength' => 5 * 1024 * 1024,
            'ContentMD5' => base64_encode(hash('md5', $part, true)),
            'Body' => $part,
        ])
            ->shouldBeCalled()
            ->willThrow(new ClientException(new MockResponse()));

        $this->client->abortMultipartUpload([
            'Bucket' => 'bucket',
            'Key' => 'test.txt',
            'UploadId' => 'upload-id',
        ])
            ->shouldBeCalled();

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Failed to upload file');

        $this->fs->write('test.txt', $contents, [
            's3' => [
                'acl' => 'public-read',
                'cache-control' => 'public, max-age=604800, immutable',
                'content-type' => 'text/plain',
                'metadata' => [
                    'my-info' => 'test',
                ],
            ],
        ]);
    }
}
