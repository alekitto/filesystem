<?php

declare(strict_types=1);

namespace Kcs\Filesystem\AsyncS3\Visibility;

use AsyncAws\S3\ValueObject\Grant;
use Kcs\Filesystem\Visibility;

interface VisibilityConverter
{
    public function visibilityToAcl(Visibility $visibility): string;

    /** @param Grant[] $grants */
    public function aclToVisibility(array $grants): Visibility;

    public function defaultForDirectories(): Visibility;
}
