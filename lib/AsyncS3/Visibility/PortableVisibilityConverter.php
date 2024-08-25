<?php

declare(strict_types=1);

namespace Kcs\Filesystem\AsyncS3\Visibility;

use AsyncAws\S3\ValueObject\Grant;
use Kcs\Filesystem\Visibility;

class PortableVisibilityConverter implements VisibilityConverter
{
    private const PUBLIC_GRANTEE_URI = 'http://acs.amazonaws.com/groups/global/AllUsers';
    private const PUBLIC_GRANTS_PERMISSION = 'READ';
    private const PUBLIC_ACL = 'public-read';
    private const PRIVATE_ACL = 'private';

    public function __construct(private readonly Visibility $defaultForDirectories = Visibility::Public)
    {
    }

    public function visibilityToAcl(Visibility $visibility): string
    {
        if ($visibility === Visibility::Public) {
            return self::PUBLIC_ACL;
        }

        return self::PRIVATE_ACL;
    }

    /** @param Grant[] $grants */
    public function aclToVisibility(array $grants): Visibility
    {
        foreach ($grants as $grant) {
            $granteeUri = $grant->getGrantee()?->getUri();
            $permission = $grant->getPermission();

            if ($granteeUri === self::PUBLIC_GRANTEE_URI && $permission === self::PUBLIC_GRANTS_PERMISSION) {
                return Visibility::Public;
            }
        }

        return Visibility::Private;
    }

    public function defaultForDirectories(): Visibility
    {
        return $this->defaultForDirectories;
    }
}
