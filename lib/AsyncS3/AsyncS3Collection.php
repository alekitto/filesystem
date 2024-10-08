<?php

declare(strict_types=1);

namespace Kcs\Filesystem\AsyncS3;

use AsyncAws\S3\ValueObject\AwsObject;
use AsyncAws\S3\ValueObject\CommonPrefix;
use Closure;
use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;

use function assert;
use function preg_quote;
use function preg_replace;

class AsyncS3Collection extends AbstractLazyCollection
{
    private string $prefixPattern;

    /** @param iterable<AwsObject|CommonPrefix> $iterator */
    public function __construct(
        private readonly iterable $iterator,
        string $prefix,
        private readonly Closure $getObjectAcl,
    ) {
        $this->prefixPattern = '#^' . preg_quote($prefix, '#') . '#';
    }

    protected function doInitialize(): void
    {
        $this->collection = new ArrayCollection();

        foreach ($this->iterator as $item) {
            $key = $item instanceof AwsObject ? $item->getKey() : $item->getPrefix();
            assert($key !== null);

            $relativeKey = preg_replace($this->prefixPattern, '', $key);
            assert($relativeKey !== null);

            /* @phpstan-ignore-next-line */
            $this->collection[] = new S3FileStat(
                $item,
                $key,
                $relativeKey,
                fn () => ($this->getObjectAcl)($key),
            );
        }
    }
}
