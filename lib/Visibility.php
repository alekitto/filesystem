<?php

declare(strict_types=1);

namespace Kcs\Filesystem;

enum Visibility: string
{
    case Public = 'public';
    case Private = 'private';
}
