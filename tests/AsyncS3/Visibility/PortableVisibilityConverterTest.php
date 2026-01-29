<?php

declare(strict_types=1);

namespace Tests\AsyncS3\Visibility;

use AsyncAws\S3\Enum\Permission;
use AsyncAws\S3\Enum\Type;
use AsyncAws\S3\ValueObject\Grant;
use Kcs\Filesystem\AsyncS3\Visibility\PortableVisibilityConverter;
use Kcs\Filesystem\Visibility;
use PHPUnit\Framework\TestCase;

class PortableVisibilityConverterTest extends TestCase
{
    public function testNullGranteeIsIgnored(): void
    {
        $converter = new PortableVisibilityConverter();

        $grants = [
            new Grant([
                'Permission' => Permission::READ,
            ]),
        ];

        self::assertSame(Visibility::Private, $converter->aclToVisibility($grants));
    }

    public function testNonReadPermissionIsNotPublic(): void
    {
        $converter = new PortableVisibilityConverter();

        $grants = [
            new Grant([
                'Grantee' => [
                    'Type' => Type::GROUP,
                    'URI' => 'http://acs.amazonaws.com/groups/global/AllUsers',
                ],
                'Permission' => Permission::WRITE,
            ]),
        ];

        self::assertSame(Visibility::Private, $converter->aclToVisibility($grants));
    }
}
