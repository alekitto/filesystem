<?php

declare(strict_types=1);

namespace Tests\AsyncS3;

use AsyncAws\Core\Configuration;
use AsyncAws\S3\ValueObject\ObjectIdentifier;
use AsyncAws\SimpleS3\SimpleS3Client;
use Kcs\Filesystem\AsyncS3\AsyncS3Filesystem;
use Kcs\Filesystem\Exception\OperationException;
use Kcs\Stream\ResourceStream;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Throwable;

class AsyncS3FilesystemIntegrationTest extends TestCase
{
    private static Process $minio;
    private static SimpleS3Client $client;
    private static string $s3Bucket;
    private AsyncS3Filesystem $fs;

    public static function setUpBeforeClass(): void
    {
        $testToken = (int) ($_ENV['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '0');

        $s3Endpoint = $_ENV['KCS_FILESYSTEM_S3_ENDPOINT'] ?? getenv('KCS_FILESYSTEM_S3_ENDPOINT') ?: null;
        self::$s3Bucket = $_ENV['KCS_FILESYSTEM_S3_BUCKET'] ?? getenv('KCS_FILESYSTEM_S3_BUCKET') ?: '';
        $shouldCreateBucket = false;
        $s3AccessKeyId = $_ENV['KCS_FILESYSTEM_S3_ACCESS_KEY'] ?? getenv('KCS_FILESYSTEM_S3_ACCESS_KEY') ?: null;
        $s3AccessSecretKey = $_ENV['KCS_FILESYSTEM_S3_SECRET_KEY'] ?? getenv('KCS_FILESYSTEM_S3_SECRET_KEY') ?: null;
        if ($s3Endpoint === null) {
            $dockerCommand = $_ENV['PHP_DOCKER'] ?? getenv('PHP_DOCKER') ?: 'docker';
            $dockerHost = $_ENV['DOCKER_HOST'] ?? getenv('DOCKER_HOST') ?: null;
            $env = [];
            if ($dockerHost !== null) {
                $env['DOCKER_HOST'] = $dockerHost;
            }

            $stopper = new Process([
                $dockerCommand,
                'stop',
                'kcs_fs_test_'.$testToken
            ]);
            $stopper->run();

            $port = 9100 + $testToken;
            self::$minio = new Process([
                $dockerCommand,
                'run',
                '--rm',
                '--name', 'kcs_fs_test_'.$testToken,
                '-e', 'MINIO_ROOT_USER=Q3AM3UQ867SPQQA43P2F',
                '-e', 'MINIO_ROOT_PASSWORD=zuf+tfteSlswRu7BJ86wekitnifILbZam1KYY3TG',
                '-p',
                $port.':9000',
                'minio/minio',
                'server',
                '/data'
            ],
                getcwd(),
                $env
            );

            self::$minio->start();
            sleep(1);
            if (null !== self::$minio->getExitCode()) {
                self::markTestSkipped('Cannot start minio server');
            }

            register_shutdown_function(static function () {
                self::$minio->stop();
            });

            $httpClient = HttpClient::create();
            $i = 10;
            while ($i-->0) {
                unset($exception);
                try {
                    $httpClient->request('GET', 'http://localhost:'.$port.'/minio/health/live');
                } catch (TransportExceptionInterface $exception) {
                    sleep(1);
                    continue;
                } catch (Throwable $exception) {
                    self::markTestSkipped('Cannot check minio server status: ' . $exception->getMessage());
                }

                unset($exception);
                break;
            }

            if (isset($exception)) {
                self::markTestSkipped('Cannot start minio server: ' . $exception->getMessage());
            }

            $s3Endpoint = 'http://localhost:'.$port;
            $s3AccessKeyId = 'Q3AM3UQ867SPQQA43P2F';
            $s3AccessSecretKey = 'zuf+tfteSlswRu7BJ86wekitnifILbZam1KYY3TG';
            self::$s3Bucket = 'filesystem-test';
            $shouldCreateBucket = true;
        }

        self::$client = new SimpleS3Client([
            Configuration::OPTION_ACCESS_KEY_ID => $s3AccessKeyId,
            Configuration::OPTION_SECRET_ACCESS_KEY => $s3AccessSecretKey,
            Configuration::OPTION_ENDPOINT => $s3Endpoint,
            Configuration::OPTION_PATH_STYLE_ENDPOINT => true,
        ]);

        if ($shouldCreateBucket) {
            self::$client->createBucket(['Bucket' => self::$s3Bucket]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$minio)) {
            self::$minio->stop();
        }
    }

    protected function setUp(): void
    {
        $this->fs = new AsyncS3Filesystem(self::$s3Bucket, '/', self::$client);
    }

    public function testExistsShouldReturnFalseIfFileDoesExists(): void
    {
        self::assertFalse($this->fs->exists('ci-non-existent/test_file'));
    }

    public function testExistsShouldReturnTrueIfFileExists(): void
    {
        $suffix = str_replace('/', '_', base64_encode(random_bytes(6)));
        self::$client->upload(self::$s3Bucket, 'ci-'.$suffix.'/', '');
        self::$client->upload(self::$s3Bucket, 'ci-'.$suffix.'/test_file', 'This is a great file');

        try {
            self::assertTrue($this->fs->exists('ci-'.$suffix.'/test_file'));
        } finally {
            self::$client->deleteObjects([
                'Bucket' => self::$s3Bucket,
                'Delete' => ['Objects' => [
                    new ObjectIdentifier(['Key' => 'ci-'.$suffix.'/test_file']),
                    new ObjectIdentifier(['Key' => 'ci-'.$suffix.'/']),
                ]]
            ]);
        }
    }

    public function testReadShouldThrowIfFileDoesNotExist(): void
    {
        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('File does not exists: HTTP 404 returned for');
        $this->fs->read('ci-non-existent/test_file');
    }

    public function testShouldWriteAndReadFile(): void
    {
        $this->fs->write('test.txt', 'Test Content');

        $r = self::$client->download(self::$s3Bucket, 'test.txt');
        self::assertEquals('Test Content', $r->getContentAsString());

        $st = $this->fs->read('test.txt');
        self::assertEquals('Test', $st->read(4));
        self::assertEquals(' Content', $st->read(100));
    }

    public function testShouldWriteAndReadBigFile(): void
    {
        $bytes = openssl_random_pseudo_bytes(6 * 1024 * 1024);
        if ($bytes === false) {
            self::markTestSkipped();
        }

        $resource = fopen('php://temp', 'wb+');
        fwrite($resource, $bytes);
        fseek($resource, 0);

        $this->fs->write('test_long.txt', new ResourceStream($resource), [
            's3' => ['content-type' => 'text/plain'],
        ]);

        $r = self::$client->download(self::$s3Bucket, 'test_long.txt');
        self::assertEquals($bytes, $r->getContentAsString());

        $st = $this->fs->read('test_long.txt');
        self::assertEquals($bytes, $st->read(PHP_INT_MAX));
    }

    public function testStatNonExistentShouldThrow(): void
    {
        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('File does not exist');

        $this->fs->stat('non_existent_file.txt');
    }

    #[Depends('testShouldWriteAndReadBigFile')]
    public function testStatShouldReturnAStatInfoObject(): void
    {
        $statObject = $this->fs->stat('test_long.txt');
        self::assertEquals(6 * 1024 * 1024, $statObject->fileSize());
        self::assertEquals('text/plain', $statObject->mimeType());
    }

    public function testDeleteNonExistentShouldNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        $this->fs->delete('non_existent_file.txt');
    }

    public function testDeleteFile(): void
    {
        $suffix = str_replace('/', '_', base64_encode(random_bytes(6)));
        self::$client->upload(self::$s3Bucket, 'ci-'.$suffix, 'Test content');
        $this->fs->delete('ci-'.$suffix);

        self::assertFalse($this->fs->exists('ci-'.$suffix));
    }
}
