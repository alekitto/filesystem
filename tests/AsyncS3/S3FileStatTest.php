<?php

declare(strict_types=1);

namespace Tests\AsyncS3;

use AsyncAws\S3\Result\HeadObjectOutput;
use AsyncAws\S3\ValueObject\AwsObject;
use Kcs\Filesystem\AsyncS3\S3FileStat;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

class S3FileStatTest extends TestCase
{
    use ProphecyTrait;

    public function testDefaultsLastModifiedForAwsObject(): void
    {
        $obj = new AwsObject([
            'Key' => 'file.txt',
            'Size' => 10,
        ]);

        $stat = new S3FileStat($obj, 'file.txt', 'file.txt', static fn () => null);

        self::assertSame(0, $stat->lastModified()->getTimestamp());
        self::assertSame(10, $stat->fileSize());
    }

    public function testDefaultsLastModifiedForHeadObject(): void
    {
        $head = $this->prophesize(HeadObjectOutput::class);
        $head->getLastModified()->willReturn(null);
        $head->getContentLength()->willReturn(0);
        $head->getContentType()->willReturn(null);

        $stat = new S3FileStat($head->reveal(), 'file.txt', 'file.txt', static fn () => null);

        self::assertSame(0, $stat->lastModified()->getTimestamp());
        self::assertSame(0, $stat->fileSize());
    }
}
