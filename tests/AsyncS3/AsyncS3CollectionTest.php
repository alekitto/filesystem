<?php

namespace Tests\AsyncS3;

use AsyncAws\S3\ValueObject\AwsObject;
use Kcs\Filesystem\AsyncS3\AsyncS3Collection;
use Kcs\Filesystem\AsyncS3\S3FileStat;
use PHPUnit\Framework\TestCase;

class AsyncS3CollectionTest extends TestCase
{
    public function testShouldQuoteRegexCorrectly(): void
    {
        $obj = new AwsObject([
            'Key' => '/asd#one/test/##two/test.log'
        ]);
        $collection = new AsyncS3Collection([$obj], '/asd#one/test/##two');
        self::assertEquals([
            new S3FileStat($obj, $obj->getKey(), '/test.log')
        ], iterator_to_array($collection));
    }
}
