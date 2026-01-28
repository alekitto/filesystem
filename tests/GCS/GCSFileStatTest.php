<?php

declare(strict_types=1);

namespace Tests\GCS;

use Google\Cloud\Storage\Acl;
use Google\Cloud\Storage\StorageObject;
use Kcs\Filesystem\GCS\GCSFileStat;
use Kcs\Filesystem\Visibility;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

class GCSFileStatTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var StorageObject|ObjectProphecy
     */
    private $object;

    protected function setUp(): void
    {
        $this->object = $this->prophesize(StorageObject::class);
        $this->object->info()->willReturn([
            'updated' => '2020-01-01T00:00:00Z',
            'size' => 10,
            'contentType' => 'text/plain',
        ]);
    }

    public function testVisibilityIsPublicForAllUsersReaders(): void
    {
        $acl = $this->prophesize(Acl::class);
        $acl->get()->willReturn([
            ['entity' => 'allUsers', 'role' => Acl::ROLE_READER],
        ]);

        $this->object->acl()->willReturn($acl->reveal());

        $stat = new GCSFileStat($this->object->reveal(), 'file.txt', 'file.txt');

        self::assertSame(Visibility::Public, $stat->visibility());
    }

    public function testVisibilityIsPrivateByDefault(): void
    {
        $acl = $this->prophesize(Acl::class);
        $acl->get()->willReturn([
            ['entity' => 'user:test@example.com', 'role' => Acl::ROLE_READER],
        ]);

        $this->object->acl()->willReturn($acl->reveal());

        $stat = new GCSFileStat($this->object->reveal(), 'file.txt', 'file.txt');

        self::assertSame(Visibility::Private, $stat->visibility());
    }
}
