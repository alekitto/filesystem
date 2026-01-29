<?php

declare(strict_types=1);

namespace Tests\GCS;

use Google\Cloud\Storage\StorageObject;
use Kcs\Filesystem\GCS\GCSCollection;
use Kcs\Filesystem\GCS\GCSFileStat;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

class GCSCollectionTest extends TestCase
{
    use ProphecyTrait;

    public function testShouldQuoteRegexCorrectly(): void
    {
        $object = $this->prophesize(StorageObject::class);
        $object->name()->willReturn('/asd#one/test/##two/test.log');
        $object->info()->willReturn([
            'updated' => '2020-01-01T00:00:00Z',
            'size' => 1,
            'contentType' => 'text/plain',
        ]);

        $collection = new GCSCollection([$object->reveal()], '/asd#one/test/##two');
        $items = iterator_to_array($collection);

        self::assertEquals([
            new GCSFileStat($object->reveal(), '/asd#one/test/##two/test.log', '/test.log')
        ], $items);
    }
}
